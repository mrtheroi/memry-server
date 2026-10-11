<?php

namespace App\Team\Domain;

interface TeamSlugs
{
    public function next(): string;
}
