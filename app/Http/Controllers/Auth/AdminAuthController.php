<?php

namespace App\Http\Controllers\Auth;

class AdminAuthController extends PasswordlessAuthController
{
    protected function guardName(): string
    {
        return 'admin';
    }
}
