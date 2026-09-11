<?php

namespace App;

enum RoleEnum: string
{
    case USER = 'user';
    case EDITOR = 'editor';
    case ADMIN = 'administrator';
}
