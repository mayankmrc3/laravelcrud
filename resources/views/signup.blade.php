<!doctype html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf_token" content="{{ csrf_token() }}">

        <title>Laravel</title>

        <!-- Fonts -->
        <link href="https://fonts.googleapis.com/css?family=Raleway:100,600" rel="stylesheet" type="text/css">

        <!-- Styles -->
        <style>
            html, body {
                background-color: #fff;
                color: #636b6f;
                font-family: 'Raleway', sans-serif;
                font-weight: 100;
                height: 100vh;
                margin: 0;
            }

            .full-height {
                height: 100vh;
            }

            .flex-center {
                align-items: center;
                display: flex;
                justify-content: center;
            }

            .position-ref {
                position: relative;
            }

            .top-right {
                position: absolute;
                right: 10px;
                top: 18px;
            }

            .content {
                text-align: center;
            }

            .title {
                font-size: 84px;
            }

            .links > a {
                color: #636b6f;
                padding: 0 25px;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: .1rem;
                text-decoration: none;
                text-transform: uppercase;
            }

            .m-b-md {
                margin-bottom: 30px;
            }
        </style>
    </head>
    <body>
        <div class="flex-center position-ref full-height">
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

            <div class="content">
                @if(isset($user))
                <form class="form-horizontal" action="{{route ('update',$user->id)}}" method="post">
                @else    
                <form class="form-horizontal" action="{{route ('signup')}}" method="post">
                @endif    
                    {{csrf_field()}}
                    <table>
                        <tr>
                            <td>Name : </td>
                            <td><input id="name" @if(isset($user)) value='{{$user->name}}' @endif type="text" name="mname"></td>
                        </tr>
                        <tr>
                            <td>Email</td>
                            <td><input id="email"  @if(isset($user)) value='{{$user->email}}' @endif required="required" value='' type="email" name="email"></td>
                        </tr>
                        <tr>
                            <td>Password</td>
                            <td><input id="password" value='' type="password" name="password"></td>
                        </tr>
                        <tr>
                            <td>&nbsp;</td>
                            <td><button id="button" type="submit">
                            @if(isset($user))
                                Update
                            @else
                                Add
                            @endif   
                            </button></td>
                        </tr>
                    </table>
                </form>
                <table>
                        @foreach ($members as $member)
                        <tr>
                            <td>{{ ++$i }}</td>
                            <td>{{ $member->name}}</td>
                            <td>{{ $member->email}}</td>
                             <td>
                               {{-- <a class="btn btn-info" href="{{ route('show',$member->id) }}">Show</a>--}}
                              {{-- <a href="{{action('SignupController@edit',$member->id)}}" class="btn btn-primary">Edit</a>--}}

                                <a class="btn btn-warning" href="{{ route('edit',$member->id) }}">Edit</a>
                                
                                <form action="{{route('destroy',$member->id)}}" method="post">
                                    {{csrf_field()}}
                                    
                                    <button type="submit" style="display: inline-block;" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                </table>
 




            </div>
        </div>
    </body>
</html>
