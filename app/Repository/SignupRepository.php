<?php

namespace App\Repository;

use App\Models\User;

class SignupRepository
{
    private $usermodel;

    public function __construct()
    {
        $this->usermodel = new Signup();
    }

    public function insertsave($data)
    {
        try 
        {
            $signup1 = new Signup();
            $signup1->name = $data['name'];
            $signup1->status = $data['status'];
            $signup1->save();
            $message['success'] = "Record saved Successfully";
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
