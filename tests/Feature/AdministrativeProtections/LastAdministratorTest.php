<?php

// FND-020 / E-25: the last-administrator protection ships with tasks 6.5-6.6.
// Until then an administrator can deactivate or strip the last administrator;
// these pending tests keep that temporary gap visible on every run.

it('E-25 rejects deactivating the last active administrator')
    ->todo(note: 'Tasks 6.5-6.6 (FND-020)');

it('E-25 rejects removing the roles of the last active administrator')
    ->todo(note: 'Tasks 6.5-6.6 (FND-020)');

it('E-25 rejects removing users.assign_roles or roles.manage from the roles of the last active administrator')
    ->todo(note: 'Tasks 6.5-6.6 (FND-020)');
