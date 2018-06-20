<?php

namespace App\Models;
use Gbrock\Table\Traits\Sortable;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use Sortable;
    protected $fillable=['name','email','password'];
    protected $sortable = ['name', 'email'];
    protected $hidden =["password","remember_token"];
}
