<?php

// Merged over the framework's lang/en/validation.php: only registration-specific text lives here.
return [
    'custom' => [
        'first_name_ar' => [
            'regex' => 'The :attribute field may only contain Arabic letters.',
        ],
        'last_name_ar' => [
            'regex' => 'The :attribute field may only contain Arabic letters.',
        ],
        'country_id' => [
            'integer' => 'Please select a valid country.',
            'exists' => 'The selected country is not valid.',
        ],
        'wilaya_id' => [
            'integer' => 'Please select a valid wilaya.',
            'required' => 'Please select a wilaya.',
            'exists' => 'The selected wilaya is not available for the selected country.',
        ],
        'commune_id' => [
            'integer' => 'Please select a valid commune.',
            'required' => 'Please select a commune.',
            'exists' => 'The selected commune is not available for the selected wilaya.',
        ],
        'email' => [
            'unique' => 'This email address is already in use.',
        ],
    ],
    'attributes' => [
        'first_name' => 'first name',
        'last_name' => 'last name',
        'first_name_ar' => 'first name (Arabic)',
        'last_name_ar' => 'last name (Arabic)',
        'country_id' => 'country',
        'wilaya_id' => 'wilaya',
        'commune_id' => 'commune',
        'city' => 'city',
        'phone' => 'phone number',
        'email' => 'email address',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
    ],
];
