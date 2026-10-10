<?php

namespace App\Team\Domain;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
