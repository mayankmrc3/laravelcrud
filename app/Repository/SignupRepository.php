<?php

namespace App\Repository;

use App\Models\User;

class SignupRepository
{
    private $usermodel;

    public function __construct()
    {
        $this->usermodel = new User();
    }

    public function insertsave($data)
    {
        try 
        {
            $user = new User;
            $user->name  = $data['mname'];
            $user->email = $data['email'];
            $user->password =$data['password'];
            $user->save();
            return("inserted successfully");
            
        } 
        catch (Exception $e) 
        {
            $message['error'] = "Error in Save";
        }
        return $message;
    }

    public function deletedata($data)
    {
        try 
        {
            foreach ($data as $key => $value) 
            {
                $signup1 = $this->usermodel->where('id', $value['id'])->delete();
                $message['success'] = "Record Deleted Successfully";
            }
        } 
        catch (Exception $e) 
        {
            $message['error'] = "No Record found";
        }
        return $message;
    }
}
