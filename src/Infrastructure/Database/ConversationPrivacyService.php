<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;
use YassinStore\AiAssistant\Domain\Chat\Outcome;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;
use YassinStore\AiAssistant\Infrastructure\Security\ConversationExportCursor;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Point-in-time export and erasure under already-proved conversation authority. */
final class ConversationPrivacyService
{
    private const MESSAGE_PAGE_SIZE = 100;
    private const RECEIPT_PAGE_SIZE = 50;
    // A turn input can carry the complete bounded image-evidence envelope.
    // Keep one turn per response so the export endpoint never combines
    // multiple maximum-size rows into an unbounded PHP/HTTP response.
    private const TURN_PAGE_SIZE = 1;
    private const OPERATION_PAGE_SIZE = 10;
    private const STEP_PAGE_SIZE = 25;
    private const ATTEMPT_PAGE_SIZE = 25;

    /** @var TransactionManager */ private $transactions;
    /** @var ConversationRepository */ private $conversations;
    /** @var ConversationExportCursor */ private $cursors;
    /** @var ActiveWorkInspector */ private $activeWork;

    public function __construct(
        TransactionManager $transactions,
        ConversationRepository $conversations,
        ConversationExportCursor $cursors,
        ActiveWorkInspector $activeWork
    ) {
        $this->transactions = $transactions;
        $this->conversations = $conversations;
        $this->cursors = $cursors;
        $this->activeWork = $activeWork;
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    public function export(array $authority, ?string $cursor): array
    {
        return $this->transactions->run(function () use ($authority, $cursor): array {
            $identity = $this->authorityIdentity($authority);
            // Match turn admission's lease -> conversation lock order. A turn
            // that acquires only its lease after this check still blocks on the
            // canonical row before it can reserve or mutate durable state.
            $this->assertIdle($identity['id'], $identity['public_id']);
            $conversation = $this->lockAuthority($identity);
            $conversationId = (int) $conversation['id'];
            if ($cursor === null) {
                $high = $this->highWater($conversationId);
                $state = $this->cursors->start($conversation, $high);
            } else {
                $state = $this->cursors->open($cursor, $conversation);
            }

            $messages = $state['messages_done']
                ? array('rows' => array(), 'after' => (int) $state['message_after'], 'done' => true)
                : $this->messages(
                    $conversationId,
                    (int) $state['message_after'],
                    (int) $state['message_high']
                );
            $receipts = $state['receipts_done']
                ? array('rows' => array(), 'after' => (int) $state['receipt_after'], 'done' => true)
                : $this->verifiedReceipts(
                    $conversationId,
                    (int) $state['receipt_after'],
                    (int) $state['receipt_high']
                );
            $turns = $state['turns_done']
                ? array('rows' => array(), 'after' => (int) $state['turn_after'], 'done' => true)
                : $this->turns($conversationId, (int) $state['turn_after'], (int) $state['turn_high']);
            $operations = $state['operations_done']
                ? array('rows' => array(), 'after' => (int) $state['operation_after'], 'done' => true)
                : $this->operations($conversationId, (int) $state['operation_after'], (int) $state['operation_high']);
            $steps = $state['steps_done']
                ? array('rows' => array(), 'after' => (int) $state['step_after'], 'done' => true)
                : $this->steps($conversationId, (int) $state['step_after'], (int) $state['step_high']);
            $attempts = $state['attempts_done']
                ? array('rows' => array(), 'after' => (int) $state['attempt_after'], 'done' => true)
                : $this->attempts($conversationId, (int) $state['attempt_after'], (int) $state['attempt_high']);
            $state['message_after'] = $messages['after'];
            $state['messages_done'] = $messages['done'];
            $state['receipt_after'] = $receipts['after'];
            $state['receipts_done'] = $receipts['done'];
            foreach (
                array(
                'turn' => $turns,
                'operation' => $operations,
                'step' => $steps,
                'attempt' => $attempts,
                ) as $name => $page
            ) {
                $state[$name . '_after'] = $page['after'];
                $state[$name . 's_done'] = $page['done'];
            }
            $complete = $messages['done'] && $receipts['done']
                && $turns['done'] && $operations['done']
                && $steps['done'] && $attempts['done'];

            return array(
                'schema' => 1,
                'conversation_id' => (string) $conversation['public_id'],
                'created_at' => (int) $state['snapshot_created_at'],
                'updated_at' => (int) $state['snapshot_updated_at'],
                'expires_at' => (int) $state['snapshot_expires_at'],
                'state' => ConversationPrivacyProjector::conversationState(
                    is_array($conversation['state'] ?? null) ? $conversation['state'] : array()
                ),
                'messages' => $messages['rows'],
                'verified_cart_receipts' => $receipts['rows'],
                'turns' => $turns['rows'],
                'cart_operations' => $operations['rows'],
                'cart_operation_steps' => $steps['rows'],
                'cart_step_attempts' => $attempts['rows'],
                'next_cursor' => $complete ? null : $this->cursors->seal($state, $conversation),
                'complete' => $complete,
            );
        });
    }

    /** @param array<string,mixed> $authority */
    public function delete(array $authority): void
    {
        $this->transactions->run(function () use ($authority): void {
            $identity = $this->authorityIdentity($authority);
            $this->assertIdle($identity['id'], $identity['public_id']);
            $conversation = $this->lockAuthority($identity);
            $conversationId = (int) $conversation['id'];
            $publicId = (string) $conversation['public_id'];

            global $wpdb;
            $this->write(
                $wpdb->prepare(
                    'DELETE a FROM ' . SchemaRegistry::operationStepAttempts() . ' a'
                    . ' INNER JOIN ' . SchemaRegistry::operationSteps() . ' s ON s.id = a.step_id'
                    . ' INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id'
                    . ' WHERE o.conversation_id = %d',
                    $conversationId
                ),
                'Unable to erase conversation cart-step attempts.'
            );
            $this->write(
                $wpdb->prepare(
                    'DELETE s FROM ' . SchemaRegistry::operationSteps() . ' s'
                    . ' INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id'
                    . ' WHERE o.conversation_id = %d',
                    $conversationId
                ),
                'Unable to erase conversation cart steps.'
            );
            foreach (
                array(
                SchemaRegistry::operations(),
                SchemaRegistry::turns(),
                SchemaRegistry::messages(),
                ) as $table
            ) {
                $this->write(
                    $wpdb->prepare("DELETE FROM {$table} WHERE conversation_id = %d", $conversationId),
                    'Unable to erase conversation child data.'
                );
            }

            $resource = 'conversation|' . $publicId;
            $this->write(
                $wpdb->prepare(
                    'DELETE FROM ' . SchemaRegistry::leases()
                    . ' WHERE resource_hash = %s AND resource = %s',
                    hash('sha256', $resource),
                    $resource
                ),
                'Unable to erase the conversation lease.'
            );
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . SchemaRegistry::conversations()
                    . ' WHERE id = %d AND public_id = %s AND session_hash = %s',
                    $conversationId,
                    $publicId,
                    (string) $conversation['session_hash']
                )
            );
            if ($deleted !== 1 || WpdbError::has($wpdb)) {
                throw new RuntimeException('Unable to erase the authorized conversation.');
            }
        });
    }

    /** @param array<string,mixed> $authority @return array{id:int,public_id:string,session_hash:string} */
    private function authorityIdentity(array $authority): array
    {
        $id = isset($authority['id']) ? (int) $authority['id'] : 0;
        $publicId = strtolower(trim((string) ($authority['public_id'] ?? '')));
        $sessionHash = strtolower(trim((string) ($authority['session_hash'] ?? '')));
        if (
            $id < 1 || !Uuid::isV4($publicId)
            || preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1
        ) {
            throw new RuntimeException('Conversation privacy authority is invalid.');
        }
        return array('id' => $id, 'public_id' => $publicId, 'session_hash' => $sessionHash);
    }

    /** @param array{id:int,public_id:string,session_hash:string} $identity @return array<string,mixed> */
    private function lockAuthority(array $identity): array
    {
        $current = $this->conversations->reloadForUpdate($identity['id']);
        if (
            !is_array($current)
            || !hash_equals($identity['public_id'], (string) ($current['public_id'] ?? ''))
            || !hash_equals($identity['session_hash'], (string) ($current['session_hash'] ?? ''))
        ) {
            throw new RuntimeException('Conversation privacy authority changed.');
        }
        return $current;
    }

    private function assertIdle(int $conversationId, string $publicId): void
    {
        if ($this->activeWork->hasForConversation($conversationId, $publicId)) {
            throw new ConversationPrivacyConflict(
                'Conversation data cannot be exported or erased while work is active.'
            );
        }
    }

    /** @return array{messages:int,receipts:int,turns:int,operations:int,steps:int,attempts:int} */
    private function highWater(int $conversationId): array
    {
        global $wpdb;
        $messageHigh = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(MAX(id),0) FROM ' . SchemaRegistry::messages()
                . ' WHERE conversation_id = %d',
                $conversationId
            )
        );
        $this->assertRead('Unable to capture the conversation message high-water mark.');
        $receiptHigh = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(MAX(o.id),0) FROM ' . SchemaRegistry::operations() . ' o'
                . ' WHERE o.conversation_id = %d AND o.status = %s AND o.receipt IS NOT NULL'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . SchemaRegistry::operations() . ' p'
                . ' WHERE p.conversation_id = o.conversation_id AND p.id <= o.id'
                . ' AND p.status IN (%s,%s))',
                $conversationId,
                OperationStatus::VERIFIED,
                OperationStatus::PREPARED,
                OperationStatus::EXECUTING
            )
        );
        $this->assertRead('Unable to capture the conversation receipt high-water mark.');
        $turnHigh = $this->maximumId(SchemaRegistry::turns(), 'conversation_id', $conversationId);
        $operationHigh = $this->maximumId(SchemaRegistry::operations(), 'conversation_id', $conversationId);
        $stepHigh = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(MAX(s.id),0) FROM ' . SchemaRegistry::operationSteps() . ' s'
                . ' INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id'
                . ' WHERE o.conversation_id = %d',
            $conversationId
        ));
        $this->assertRead('Unable to capture the conversation cart-step high-water mark.');
        $attemptHigh = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(MAX(a.id),0) FROM ' . SchemaRegistry::operationStepAttempts() . ' a'
                . ' INNER JOIN ' . SchemaRegistry::operationSteps() . ' s ON s.id = a.step_id'
                . ' INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id'
                . ' WHERE o.conversation_id = %d',
            $conversationId
        ));
        $this->assertRead('Unable to capture the conversation cart-attempt high-water mark.');
        foreach (array($messageHigh, $receiptHigh, $turnHigh, $operationHigh, $stepHigh, $attemptHigh) as $value) {
            if (
                (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1))
                || (int) $value < 0
            ) {
                throw new RuntimeException('Conversation export high-water evidence is invalid.');
            }
        }
        return array(
            'messages' => (int) $messageHigh,
            'receipts' => (int) $receiptHigh,
            'turns' => (int) $turnHigh,
            'operations' => (int) $operationHigh,
            'steps' => (int) $stepHigh,
            'attempts' => (int) $attemptHigh,
        );
    }

    /** @return array{rows:array<int,array<string,mixed>>,after:int,done:bool} */
    private function messages(int $conversationId, int $after, int $high): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,role,outcome,content,payload,created_at FROM ' . SchemaRegistry::messages()
                . ' WHERE conversation_id = %d AND id > %d AND id <= %d'
                . ' ORDER BY id ASC LIMIT %d',
                $conversationId,
                $after,
                $high,
                self::MESSAGE_PAGE_SIZE + 1
            ),
            ARRAY_A
        );
        $this->assertRead('Unable to export conversation messages.');
        if (!is_array($rows)) {
            throw new RuntimeException('Conversation message export returned invalid rows.');
        }

        $hasMore = count($rows) > self::MESSAGE_PAGE_SIZE;
        $page = array_slice($rows, 0, self::MESSAGE_PAGE_SIZE);
        $messages = array();
        $lastId = $after;
        foreach ($page as $row) {
            $id = (int) ($row['id'] ?? 0);
            $role = (string) ($row['role'] ?? '');
            $outcome = (string) ($row['outcome'] ?? '');
            $content = (string) ($row['content'] ?? '');
            if (
                $id <= $lastId || $id > $high
                || !in_array($role, array('user', 'assistant'), true)
                || ($role === 'user' && $outcome !== '')
                || ($role === 'assistant' && !in_array($outcome, Outcome::all(), true))
                || trim($content) === ''
            ) {
                throw new RuntimeException('Conversation message export encountered corrupt evidence.');
            }
            $messages[] = array(
                'role' => $role,
                'outcome' => $outcome,
                'text' => $content,
                'payload' => ConversationPrivacyProjector::messagePayload(
                    $role,
                    Json::decodeOptionalObject(
                        (string) ($row['payload'] ?? ''),
                        'Conversation message payload'
                    )
                ),
                'created_at' => self::staticTimestamp((string) ($row['created_at'] ?? '')),
            );
            $lastId = $id;
        }
        if ($page === array() || (!$hasMore && $lastId !== $high)) {
            throw new RuntimeException('Conversation message export high-water evidence changed.');
        }
        return array(
            'rows' => $messages,
            'after' => $hasMore ? $lastId : $high,
            'done' => !$hasMore,
        );
    }

    /** @return array{rows:array<int,array<string,mixed>>,after:int,done:bool} */
    private function turns(int $conversationId, int $after, int $high): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id,turn_id,status,input_payload,response_payload,failure_code,created_at,updated_at,completed_at'
                . ' FROM ' . SchemaRegistry::turns()
                . ' WHERE conversation_id = %d AND id > %d AND id <= %d'
                . ' ORDER BY id ASC LIMIT %d',
            $conversationId,
            $after,
            $high,
            self::TURN_PAGE_SIZE + 1
        ), ARRAY_A);
        $this->assertRead('Unable to export conversation turns.');
        return $this->page($rows, $after, $high, self::TURN_PAGE_SIZE, static function (array $row): array {
            return array(
                'turn_id' => (string) ($row['turn_id'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'input' => ConversationPrivacyProjector::turnInput(
                    Json::decodeRequiredObject((string) ($row['input_payload'] ?? ''), 'Turn input')
                ),
                'response' => ConversationPrivacyProjector::turnResponse(
                    Json::decodeOptionalObject((string) ($row['response_payload'] ?? ''), 'Turn response')
                ),
                'failure_code' => (string) ($row['failure_code'] ?? ''),
                'created_at' => self::staticTimestamp((string) ($row['created_at'] ?? '')),
                'updated_at' => self::staticTimestamp((string) ($row['updated_at'] ?? '')),
                'completed_at' => self::staticOptionalTimestamp((string) ($row['completed_at'] ?? '')),
            );
        }, 'turn');
    }

    /** @return array{rows:array<int,array<string,mixed>>,after:int,done:bool} */
    private function operations(int $conversationId, int $after, int $high): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id,public_id,turn_id,status,plan,pre_state,applied_effects,post_state,receipt,'
                . 'failure_code,safe_message,created_at,updated_at,completed_at'
                . ' FROM ' . SchemaRegistry::operations()
                . ' WHERE conversation_id = %d AND id > %d AND id <= %d'
                . ' ORDER BY id ASC LIMIT %d',
            $conversationId,
            $after,
            $high,
            self::OPERATION_PAGE_SIZE + 1
        ), ARRAY_A);
        $this->assertRead('Unable to export conversation cart operations.');
        return $this->page($rows, $after, $high, self::OPERATION_PAGE_SIZE, static function (array $row): array {
            $receipt = trim((string) ($row['receipt'] ?? ''));
            return array(
                'id' => (string) ($row['public_id'] ?? ''),
                'turn_id' => (string) ($row['turn_id'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'plan' => ConversationPrivacyProjector::cartPlan(
                    Json::decodeRequiredObject((string) ($row['plan'] ?? ''), 'Cart operation plan')
                ),
                'pre_state' => ConversationPrivacyProjector::cartSnapshot(
                    Json::decodeRequiredObject((string) ($row['pre_state'] ?? ''), 'Cart operation pre-state')
                ),
                'applied_effects' => ConversationPrivacyProjector::appliedEffects(
                    Json::decodeOptionalObject((string) ($row['applied_effects'] ?? ''), 'Cart operation effects')
                ),
                'post_state' => ConversationPrivacyProjector::optionalCartSnapshot(
                    Json::decodeOptionalObject((string) ($row['post_state'] ?? ''), 'Cart operation post-state')
                ),
                'receipt' => $receipt === '' ? null : ActionReceipt::fromArray(
                    Json::decodeRequiredObject($receipt, 'Cart operation receipt')
                )->forClient(),
                'failure_code' => (string) ($row['failure_code'] ?? ''),
                'safe_message' => (string) ($row['safe_message'] ?? ''),
                'created_at' => self::staticTimestamp((string) ($row['created_at'] ?? '')),
                'updated_at' => self::staticTimestamp((string) ($row['updated_at'] ?? '')),
                'completed_at' => self::staticOptionalTimestamp((string) ($row['completed_at'] ?? '')),
            );
        }, 'cart operation');
    }

    /** @return array{rows:array<int,array<string,mixed>>,after:int,done:bool} */
    private function steps(int $conversationId, int $after, int $high): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT s.id,s.public_id,o.public_id AS operation_public_id,s.step_index,s.command_index,s.status,'
                . 's.primitive,s.pre_state,s.post_state,s.failure_code,s.safe_message,'
                . 's.created_at,s.updated_at,s.completed_at'
                . ' FROM ' . SchemaRegistry::operationSteps() . ' s'
                . ' INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id'
                . ' WHERE o.conversation_id = %d AND s.id > %d AND s.id <= %d'
                . ' ORDER BY s.id ASC LIMIT %d',
            $conversationId,
            $after,
            $high,
            self::STEP_PAGE_SIZE + 1
        ), ARRAY_A);
        $this->assertRead('Unable to export conversation cart steps.');
        return $this->page($rows, $after, $high, self::STEP_PAGE_SIZE, static function (array $row): array {
            return array(
                'id' => (string) ($row['public_id'] ?? ''),
                'operation_id' => (string) ($row['operation_public_id'] ?? ''),
                'step_index' => (int) ($row['step_index'] ?? 0),
                'command_index' => (int) ($row['command_index'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'primitive' => ConversationPrivacyProjector::cartPrimitive(
                    Json::decodeRequiredObject((string) ($row['primitive'] ?? ''), 'Cart step primitive')
                ),
                'pre_state' => ConversationPrivacyProjector::cartSnapshot(
                    Json::decodeRequiredObject((string) ($row['pre_state'] ?? ''), 'Cart step pre-state')
                ),
                'post_state' => ConversationPrivacyProjector::optionalCartSnapshot(
                    Json::decodeOptionalObject((string) ($row['post_state'] ?? ''), 'Cart step post-state')
                ),
                'failure_code' => (string) ($row['failure_code'] ?? ''),
                'safe_message' => (string) ($row['safe_message'] ?? ''),
                'created_at' => self::staticTimestamp((string) ($row['created_at'] ?? '')),
                'updated_at' => self::staticTimestamp((string) ($row['updated_at'] ?? '')),
                'completed_at' => self::staticOptionalTimestamp((string) ($row['completed_at'] ?? '')),
            );
        }, 'cart step');
    }

    /** @return array{rows:array<int,array<string,mixed>>,after:int,done:bool} */
    private function attempts(int $conversationId, int $after, int $high): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT a.id,a.public_id,s.public_id AS step_public_id,a.attempt_number,a.status,'
                . 'a.candidate_post_state,a.failure_code,a.safe_message,'
                . 'a.created_at,a.updated_at,a.completed_at'
                . ' FROM ' . SchemaRegistry::operationStepAttempts() . ' a'
                . ' INNER JOIN ' . SchemaRegistry::operationSteps() . ' s ON s.id = a.step_id'
                . ' INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id'
                . ' WHERE o.conversation_id = %d AND a.id > %d AND a.id <= %d'
                . ' ORDER BY a.id ASC LIMIT %d',
            $conversationId,
            $after,
            $high,
            self::ATTEMPT_PAGE_SIZE + 1
        ), ARRAY_A);
        $this->assertRead('Unable to export conversation cart attempts.');
        return $this->page($rows, $after, $high, self::ATTEMPT_PAGE_SIZE, static function (array $row): array {
            return array(
                'id' => (string) ($row['public_id'] ?? ''),
                'step_id' => (string) ($row['step_public_id'] ?? ''),
                'attempt_number' => (int) ($row['attempt_number'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'candidate_post_state' => ConversationPrivacyProjector::optionalCartSnapshot(
                    Json::decodeOptionalObject(
                        (string) ($row['candidate_post_state'] ?? ''),
                        'Cart attempt post-state'
                    )
                ),
                'failure_code' => (string) ($row['failure_code'] ?? ''),
                'safe_message' => (string) ($row['safe_message'] ?? ''),
                'created_at' => self::staticTimestamp((string) ($row['created_at'] ?? '')),
                'updated_at' => self::staticTimestamp((string) ($row['updated_at'] ?? '')),
                'completed_at' => self::staticOptionalTimestamp((string) ($row['completed_at'] ?? '')),
            );
        }, 'cart attempt');
    }

    /** @return array{rows:array<int,array<string,mixed>>,after:int,done:bool} */
    private function verifiedReceipts(int $conversationId, int $after, int $high): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,receipt,completed_at FROM ' . SchemaRegistry::operations()
                . ' WHERE conversation_id = %d AND id > %d AND id <= %d'
                . ' AND status = %s AND receipt IS NOT NULL'
                . ' ORDER BY id ASC LIMIT %d',
                $conversationId,
                $after,
                $high,
                OperationStatus::VERIFIED,
                self::RECEIPT_PAGE_SIZE + 1
            ),
            ARRAY_A
        );
        $this->assertRead('Unable to export verified cart receipts.');
        if (!is_array($rows)) {
            throw new RuntimeException('Conversation receipt export returned invalid rows.');
        }

        $hasMore = count($rows) > self::RECEIPT_PAGE_SIZE;
        $page = array_slice($rows, 0, self::RECEIPT_PAGE_SIZE);
        $receipts = array();
        $lastId = $after;
        foreach ($page as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= $lastId || $id > $high) {
                throw new RuntimeException('Conversation receipt export encountered corrupt identity.');
            }
            $receipt = ActionReceipt::fromArray(
                Json::decodeRequiredObject((string) ($row['receipt'] ?? ''), 'Verified cart receipt')
            )->forClient();
            $receipts[] = array(
                'receipt' => $receipt,
                'completed_at' => self::staticTimestamp((string) ($row['completed_at'] ?? '')),
            );
            $lastId = $id;
        }
        if ($page === array() || (!$hasMore && $lastId !== $high)) {
            throw new RuntimeException('Conversation receipt export high-water evidence changed.');
        }
        return array(
            'rows' => $receipts,
            'after' => $hasMore ? $lastId : $high,
            'done' => !$hasMore,
        );
    }

    private function maximumId(string $table, string $column, int $conversationId): int
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(id),0) FROM {$table} WHERE {$column} = %d",
            $conversationId
        ));
        $this->assertRead('Unable to capture conversation export high-water evidence.');
        if (
            (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1))
            || (int) $value < 0
        ) {
            throw new RuntimeException('Conversation export high-water evidence is invalid.');
        }
        return (int) $value;
    }

    /**
     * @param mixed $rows
     * @param callable(array<string,mixed>):array<string,mixed> $project
     * @return array{rows:array<int,array<string,mixed>>,after:int,done:bool}
     */
    private function page($rows, int $after, int $high, int $limit, callable $project, string $label): array
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Conversation ' . $label . ' export returned invalid rows.');
        }
        $hasMore = count($rows) > $limit;
        $source = array_slice($rows, 0, $limit);
        $exported = array();
        $lastId = $after;
        foreach ($source as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Conversation ' . $label . ' export encountered corrupt evidence.');
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= $lastId || $id > $high) {
                throw new RuntimeException('Conversation ' . $label . ' export encountered corrupt identity.');
            }
            $exported[] = $project($row);
            $lastId = $id;
        }
        if ($source === array() || (!$hasMore && $lastId !== $high)) {
            throw new RuntimeException('Conversation ' . $label . ' export high-water evidence changed.');
        }
        return array(
            'rows' => $exported,
            'after' => $hasMore ? $lastId : $high,
            'done' => !$hasMore,
        );
    }

    private static function staticTimestamp(string $value): int
    {
        $timestamp = $value !== '' ? strtotime($value . ' UTC') : false;
        if ($timestamp === false || $timestamp < 1) {
            throw new RuntimeException('Conversation privacy timestamp is invalid.');
        }
        return $timestamp;
    }

    private static function staticOptionalTimestamp(string $value): ?int
    {
        return $value === '' ? null : self::staticTimestamp($value);
    }

    private function write(string $sql, string $message): void
    {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false || WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }

    private function assertRead(string $message): void
    {
        global $wpdb;
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }
}
