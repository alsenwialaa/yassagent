<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Terminal;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;

final class RespondFollowUpHandler extends AbstractTerminalHandler
{
    /** @var ToolContract */ private $contract;

    public function __construct()
    {
        $this->contract = new ToolContract(
            'respond_follow_up',
            ToolPromptDescriptions::for('respond_follow_up'),
            ToolSchemas::closedObject(array(
                'question' => ToolSchemas::described(
                    ToolSchemas::boundedText(320),
                    'One natural Arabic customer-facing question authored by the model.'
                ),
                'purpose' => ToolSchemas::described(array(
                    'type' => 'string',
                    'enum' => array(
                        'ordinary', 'cart_ambiguity', 'cart_continuation',
                        'cart_continuation_retry',
                    ),
                ), 'ordinary outside cart resolution; cart_ambiguity for unbounded uncertainty; cart_continuation creates one server-bindable missing value; cart_continuation_retry adaptively re-asks the unchanged active missing value.'),
                'product_refs' => ToolSchemas::described(
                    ToolSchemas::productReferences(),
                    'Optional cards. For cart_continuation these are allowed only for missing=target candidate products; cart_continuation_retry forbids cards.'
                ),
                'variation_refs' => ToolSchemas::described(
                    ToolSchemas::variationReferences(),
                    'Optional exact-variation cards. For cart_continuation these are allowed only for missing=target candidate variations; cart_continuation_retry forbids cards.'
                ),
                'cart_continuation' => ToolSchemas::described(ToolSchemas::closedObject(array(
                    'action' => ToolSchemas::described(array(
                        'type' => 'string',
                        'enum' => array(
                            CartCommand::ADD, CartCommand::UPDATE,
                            CartCommand::REMOVE, CartCommand::REPLACE,
                        ),
                    ), 'Exact customer-requested cart action.'),
                    'target_ref' => ToolSchemas::described(
                        ToolSchemas::reference(),
                        'Fresh product_ref for missing variation, or cart_item_ref for missing update quantity.'
                    ),
                    'source_cart_item_ref' => ToolSchemas::described(
                        ToolSchemas::opaqueRef('c'),
                        'Fresh source line required only for replace variation clarification.'
                    ),
                    'intent_text' => ToolSchemas::described(
                        ToolSchemas::boundedText(320),
                        'Shortest byte-exact current customer substring proving this clarification or refinement.'
                    ),
                    'missing' => ToolSchemas::described(array(
                        'type' => 'string',
                        'enum' => array(
                            PendingCartIntent::MISSING_VARIATION,
                            PendingCartIntent::MISSING_QUANTITY,
                            PendingCartIntent::MISSING_TARGET,
                        ),
                    ), 'The one unresolved semantic field represented by this continuation.'),
                    'quantity_mode' => ToolSchemas::described(array(
                        'type' => 'string',
                        'enum' => array(
                            'set', 'increment', 'decrement', 'preserve', 'exact',
                        ),
                    ), 'Required only where the action contract needs an already-known quantity meaning.'),
                    'quantity' => ToolSchemas::described(
                        array('type' => 'integer', 'minimum' => 1, 'maximum' => CartQuantity::MAX),
                        'Already explicit add or replacement quantity; never a guessed missing quantity.'
                    ),
                    'selected_attributes' => ToolSchemas::described(array(
                        'type' => 'array',
                        'items' => ToolSchemas::closedObject(array(
                            'label' => ToolSchemas::boundedText(160),
                            'value' => ToolSchemas::boundedText(160),
                        ), array('label', 'value')),
                        'maxItems' => 16,
                        'uniqueItems' => true,
                    ), 'Live variation-axis values explicitly supplied by the customer in this request or refinement.'),
                    'candidate_commands' => ToolSchemas::described(array(
                        'type' => 'array',
                        'items' => ToolSchemas::cartCommand(),
                        'minItems' => 2,
                        'maxItems' => 8,
                        'uniqueItems' => true,
                    ), 'For missing=target only: every complete bounded live command that differs only by target.'),
                ), array('action', 'missing', 'intent_text')), 'Server-bindable descriptor for one AI-authored cart clarification.'),
            ), array('question', 'purpose')),
            ToolContract::TERMINAL
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }
}
