<?php

declare(strict_types=1);

return [
    'name'        => 'Email Verification and Double Opt-In (DOI) by Leuchtfeuer',
    'description' => 'Universal email verification for Mautic, including Double Opt-In (DOI)',
    'version'     => '5.3.1',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
    'routes'      => [
        'main' => [
            'mautic_doi_formaction_action' => [
                'path'       => '/forms-doi/action/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\LeuchtfeuerDoiBundle\Controller\DoiActionController::executeAction',
            ],
            'mautic_doi_render_skip_condition_action' => [
                'path'       => '/forms-doi/render-skip-condition',
                'controller' => 'MauticPlugin\LeuchtfeuerDoiBundle\Controller\ConditionBuilderController::renderSkipConditionAction',
            ],
            'mautic_doi_render_doi_action_condition_action' => [
                'path'       => '/forms-doi/render-doi-action-condition',
                'controller' => 'MauticPlugin\LeuchtfeuerDoiBundle\Controller\ConditionBuilderController::renderDoiActionConditionAction',
            ],
        ],
        'public' => [
            'mautic_doi_email_verify_action' => [
                'path'       => '/email/verify/{token}',
                'controller' => 'MauticPlugin\LeuchtfeuerDoiBundle\Controller\PublicController::verifyEmailAction',
            ],
        ],
    ],
];
