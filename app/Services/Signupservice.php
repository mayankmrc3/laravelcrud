<?php

namespace App\Services;
use App\Repository\SignupRepository;

class Signupservice 
{
    private $signuprespository;

    public function __construct($value='')
    {
        $this->signuprespository = new SignupRepository();
    }

    public function insertsave($data)
    {
        return $this->signuprespository->insertsave($data);
    }
    public function deletedata($data)
    {
        return $this->signuprespository->deletedata($data);
    }
}
