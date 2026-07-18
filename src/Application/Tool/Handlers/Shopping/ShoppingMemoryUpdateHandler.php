<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Shopping;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemoryPatch;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemoryPrivacyPolicy;

/** Records AI-interpreted shopping context without granting commerce authority. */
final class ShoppingMemoryUpdateHandler implements ToolHandlerInterface
{
    /** @var ToolContract */ private $contract;

    public function __construct()
    {
        $constraint = ToolSchemas::closedObject(array(
            'key' => array(
                'type' => 'string',
                'enum' => ShoppingMemoryPrivacyPolicy::allowedConstraintKeys(),
            ),
            'value' => ToolSchemas::boundedText(160),
            'priority' => array('type' => 'string', 'enum' => array('required', 'preferred')),
            'polarity' => array('type' => 'string', 'enum' => array('include', 'exclude')),
        ), array('key', 'value', 'priority', 'polarity'));

        $this->contract = new ToolContract(
            'shopping_memory_update',
            ToolPromptDescriptions::for('shopping_memory_update'),
            ToolSchemas::closedObject(array(
                'mode' => array('type' => 'string', 'enum' => array('merge', 'replace_topic', 'clear')),
                'goal' => ToolSchemas::boundedText(320),
                'stage' => array(
                    'type' => 'string',
                    'enum' => array('discovering', 'comparing', 'configuring', 'deciding', 'cart'),
                ),
                'constraints' => array(
                    'type' => 'array',
                    'items' => $constraint,
                    'maxItems' => 16,
                    'uniqueItems' => true,
                ),
                'remove_constraint_keys' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'string',
                        'enum' => ShoppingMemoryPrivacyPolicy::allowedConstraintKeys(),
                    ),
                    'maxItems' => 16,
                    'uniqueItems' => true,
                ),
                'compared_product_refs' => ToolSchemas::productReferences(),
                'unresolved_question' => array('type' => 'string', 'maxLength' => 320),
            ), array('mode')),
            ToolContract::STATE
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        $payload = $arguments;
        $refs = isset($payload['compared_product_refs']) && is_array($payload['compared_product_refs'])
            ? $payload['compared_product_refs'] : array();
        unset($payload['compared_product_refs']);
        if ($refs !== array()) {
            $products = array();
            foreach ($refs as $ref) {
                $product = $context->authority()->requireProduct((string) $ref);
                $id = isset($product['id']) && is_int($product['id']) ? $product['id'] : 0;
                $name = isset($product['name']) && is_string($product['name']) ? trim($product['name']) : '';
                if ($id < 1 || $name === '') {
                    return ToolExecutionResult::failure('shopping_memory_product_invalid');
                }
                $products[] = array('id' => $id, 'name' => $name);
            }
            $payload['compared_products'] = $products;
        }

        try {
            $patch = new ShoppingMemoryPatch($payload);
        } catch (\InvalidArgumentException $exception) {
            throw new ContractViolation(
                'shopping_memory_patch_invalid',
                'The shopping-memory transition is invalid: ' . $exception->getMessage()
            );
        }
        $context->effects()->recordShoppingMemoryPatch($patch);

        $accepted = $patch->toArray();
        if (isset($accepted['compared_products'])) {
            $accepted['compared_products'] = array_map(static function (array $row): array {
                return array('name' => $row['name']);
            }, $accepted['compared_products']);
        }
        return ToolExecutionResult::success(array('accepted' => $accepted));
    }
}
