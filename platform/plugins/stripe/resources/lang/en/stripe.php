<?php

return [
    'webhook_secret' => 'Webhook Secret',
    'webhook_setup_guide' => [
        'title' => 'Stripe Webhook Setup Guide',
        'description' => 'Follow these steps to set up a Stripe webhook',
        'step_1_label' => 'Login to the Stripe Dashboard',
        'step_1_description' => 'Access the :link and click on the "Add Endpoint" button in the "Webhooks" section of the "Developers" tab.',
        'step_2_label' => 'Select Events and Configure Endpoint',
        'step_2_description' => 'Enter the following URL in the "Endpoint URL" field: :url. Select these events to listen for: payment_intent.succeeded, payment_intent.payment_failed, payment_intent.canceled, charge.refunded, charge.dispute.created, charge.dispute.closed.',
        'step_3_label' => 'Add Endpoint',
        'step_3_description' => 'Click the "Add Endpoint" button to save the webhook.',
        'step_4_label' => 'Copy Signing Secret',
        'step_4_description' => 'Copy the "Signing Secret" value from the "Webhook Details" section and paste it into the "Webhook Secret" field in the Stripe payment settings.',
    ],
];

