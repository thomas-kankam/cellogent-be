<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Auth\ClientSignupRequest;

/**
 * Same individual/business rules as client self-signup.
 * Use multipart/form-data when account_type=business (logo file required).
 */
class OnboardClientRequest extends ClientSignupRequest {}
