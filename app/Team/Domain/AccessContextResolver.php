<?php

namespace App\Team\Domain;

use Illuminate\Contracts\Auth\Authenticatable;

interface AccessContextResolver
{
    public function resolve(?Authenticatable $user): AccessContext;
}
