<?php

return [
    // Same text for a wrong password, an unknown email and an inactive user (FND-002).
    'failed' => 'Las credenciales proporcionadas no son válidas.',

    // Shown while an email is locked out (FND-003, DEC-018). Never reveals whether the account exists.
    'locked' => '{1} Demasiados intentos fallidos. Intente de nuevo en :minutes minuto.|[2,*] Demasiados intentos fallidos. Intente de nuevo en :minutes minutos.',
];
