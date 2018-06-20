@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="flex-center position-ref full-height">
            @if (\Session::has('order_success'))
                <div class="alert alert-success">
                    <ul>
                        <li>Payment done successfully.</li>
                    </ul>
                </div>
            @endif
            @if (Route::has('login'))
                <div class="top-right links">
                    @if (Auth::check())
                        <a href="{{ url('/home') }}">Home</a>
                    @else
                        <a href="{{ url('/login') }}">Login</a>
                        <a href="{{ url('/register') }}">Register</a>
                    @endif
                </div>
            @endif
            <div class="panel panel-default">
                <div class="panel-heading">Laravel Basic</div>
            <div class="panel-body">
                <div class="content">
                    @if(isset($user))
                    <form class="form-horizontal" action="{{route ('update',$user->id)}}" method="post">
                    @else    
                    <form class="form-horizontal" action="{{route ('signup')}}" method="post">
                    @endif    
                        {{csrf_field()}}
                        <div class="form-group">
                            <label for="name" class="col-md-4 control-label">Name</label> 
                            <div class="col-md-6"><input id="name" @if(isset($user)) value='{{$user->name}}' @endif name="mname" autofocus="autofocus" class="form-control" type="text"></div>
                        </div>
                        <div class="form-group">
                            <label for="email" class="col-md-4 control-label">Email</label>
                            <div class="col-md-6"><input id="email"  @if(isset($user)) value='{{$user->email}}' @endif required="required" value='' class="form-control" type="email" name="email"></div>
                        </div>
                        @if(!isset($user))
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Password</label>
                            <div class="col-md-6"><input id="password" value='' type="password" class="form-control"  name="password"></div>
                        </div>
                        @endif    
                        <div class="form-group">
                            <div class="col-md-6 pull-right"><button id="button" class="btn btn-primary" type="submit">
                            @if(isset($user))
                                Update
                            @else
                                Add
                            @endif   
                            </button></div>
                        </div>
                    </form>
                    <a class="pull-right" href="{{route ('makeorder')}}">donate us</a>
                </div>
            </div>
        </div>
    </div>
    @if(!empty($members))
    <div class="row">
        <div class="col-sm-12">
            <div class="table-responsive">
                <table class="table table-bordred table-striped" id="dataTable" role="grid" aria-describedby="dataTable_info" style="width: 100%;" width="100%" cellspacing="0">
                        <tr>
                            <th>#</th>
                            <th >
                                Name
                                    <span class="fa fa-sort-asc">asc</span>
                                
                                    <span class="fa fa-sort-desc">desc</span>
                                
                            </th>
                            <th scope="col">Email</th>
                            <th scope="col">&nbsp;</th>
                            <th scope="col">&nbsp;</th>
                        </tr>
                        @foreach ($members as $member)
                        <tr>
                            <td>{{ ++$i }}</td>
                            <td>{{ $member->name}}</td>
                            <td>{{ $member->email}}</td>
                            <td>
                               {{-- <a class="btn btn-info" href="{{ route('show',$member->id) }}">Show</a>--}}
                               {{-- <a href="{{action('SignupController@edit',$member->id)}}" class="btn btn-primary">Edit</a>--}}
                                <a class="btn btn-warning btn-rounded btn-sm my-0"  href="{{ route('edit',$member->id) }}">Edit</a>
                            </td>
                            <td>        
                                <form action="{{route('destroy',$member->id)}}" method="post">
                                    {{csrf_field()}}            
                                    <button type="submit" style="display: inline-block;" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </table>
                    <div class="pull-right">
                        {{ $members->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
