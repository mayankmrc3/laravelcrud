<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\validate;
use Illuminate\Http\Request;
use App\Services\Signupservice;

class SignupController extends Controller
{
    private $taskservice;

    public function __construct()
    {
        $this->taskservice = new Signupservice();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $members = User::latest()->paginate(5);
        return view('signup',compact('members'))->with('i', (request()->input('page', 1) - 1) * 5);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('signup');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        /*request()->validate([
            'name' => 'required',
            'email' => 'required',
        ]);*/
        
        $data= $request->all();

        $message = $this->taskservice->insertsave($data);
        return redirect()->route('index')->with('success','Member created successfully');
        echo json_encode($message);      
       
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        
        $user = User::where('id', $id)
                        ->first();
        $members = User::latest()->paginate(5);
        return view('signup',compact('members','user','id'))->with('i', (request()->input('page', 1) - 1) * 5);
        
        //return view('edit',compact('member'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        $user->name = $request->get('mname');
        $user->email = $request->get('email');
        //$user->password = $request->get('password');
        $user->save();
        //return("inserted successfully");
        return redirect()->route('index')->with('success','User updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $user = User::find($id);
        $user->delete();
        return redirect()->route('index')->with('success','User deleted successfully');
    }

    public function insertsave($data)
    {
        throw new \Exception('Method not implemented');
    }
}
