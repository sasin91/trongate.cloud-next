<?php
$config['login'] = [
    'max_failed_attempts' => 10,
    'block_duration' => 900,
    'password_hash_cost' => 12,
    'user_levels' => [
        1 => [
            'target_table' => 'trongate_administrators',
            'user_ref_field' => 'trongate_user_id',
            'redirect_on_success' => 'apps',
            'view_file' => 'control_plane',
            'allow_remember' => 0,
            'enable_forgot_password' => false,
            'fields' => [
                'identifiers' => ['username' => ['column' => 'username', 'label' => 'Email or username']],
                'password' => ['column' => 'password', 'label' => 'Password']
            ]
        ],
        2 => [
            'target_table' => 'team_members',
            'user_ref_field' => 'trongate_user_id',
            'redirect_on_success' => 'apps',
            'view_file' => 'control_plane',
            'allow_remember' => 0,
            'enable_forgot_password' => false,
            'fields' => [
                'identifiers' => ['email' => ['column' => 'email', 'label' => 'Email']],
                'password' => ['column' => 'password', 'label' => 'Password']
            ]
        ],
    ]
];
