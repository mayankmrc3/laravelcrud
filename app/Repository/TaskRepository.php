<?php

namespace App\Repository;

use App\Models\Task;

class TaskRepository
{
    private $taskmodel;

    public function __construct()
    {
        $this->taskmodel = new Task();
    }

    public function insertsave($data)
    {
        try {
           
            
                    $task1 = new Task();
               
                
                $task1->name = $data['name'];
                $task1->status = $data['status'];
                $task1->save();
                $message['success'] = "Saved Record Successfully";
    
        } catch (Exception $e) {
            $message['error'] = "Not Record Save";
        }
        return $message;
    }

    public function deletedata($data)
    {
        try {
            foreach ($data as $key => $value) 
            {
                $task1 = $this->taskmodel->where('id', $value['id'])->delete();
                $message['success'] = "Saved Delete Successfully";
            }
        } catch (Exception $e) {
            $message['error'] = "Not Record delete";
        }
        return $message;
    }
}
