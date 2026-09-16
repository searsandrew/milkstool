<?php

namespace App\Models;

use Database\Factories\ApiClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name'])]
class ApiClient extends Authenticatable
{
    /** @use HasFactory<ApiClientFactory> */
    use HasApiTokens, HasFactory;
}
