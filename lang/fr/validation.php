<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'unique' => 'Cette valeur du champ :attribute est déjà utilisée.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'exists' => 'La valeur sélectionnée pour :attribute est invalide.',
    'max' => [
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'min' => [
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute a été trouvé dans une fuite de données. Choisissez-en un autre.',
    ],
    'custom' => [
        'first_name_ar' => [
            'regex' => 'Le champ :attribute ne doit contenir que des lettres arabes.',
        ],
        'last_name_ar' => [
            'regex' => 'Le champ :attribute ne doit contenir que des lettres arabes.',
        ],
        'country_id' => [
            'integer' => 'Veuillez sélectionner un pays valide.',
            'exists' => 'Le pays sélectionné n’est pas valide.',
        ],
        'wilaya_id' => [
            'integer' => 'Veuillez sélectionner une wilaya valide.',
            'required' => 'Veuillez sélectionner une wilaya.',
            'exists' => 'La wilaya sélectionnée n’est pas disponible pour le pays choisi.',
        ],
        'commune_id' => [
            'integer' => 'Veuillez sélectionner une commune valide.',
            'required' => 'Veuillez sélectionner une commune.',
            'exists' => 'La commune sélectionnée n’est pas disponible pour la wilaya choisie.',
        ],
        'email' => [
            'unique' => 'Cette adresse e-mail est déjà utilisée.',
        ],
    ],
    'attributes' => [
        'first_name' => 'prénom',
        'last_name' => 'nom',
        'first_name_ar' => 'prénom (arabe)',
        'last_name_ar' => 'nom (arabe)',
        'country_id' => 'pays',
        'wilaya_id' => 'wilaya',
        'commune_id' => 'commune',
        'city' => 'ville',
        'phone' => 'téléphone',
        'email' => 'adresse e-mail',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
    ],
];
