<html>
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<head>
  
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Admin | Dashboard</title>
    @include('includes.css')
    @yield('customcss')
</head>
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper"> <!-- wrapper start -->
@include('includes.header')
@include('includes.leftpanel')
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    @include('includes.breadcrumb')
    
    @yield('content')

    <!-- /.content -->
  </div>

  @include('includes.footer')
  @include('includes.rightpanel')
  <div class="control-sidebar-bg"></div>

</div> <!-- End wrapper  -->

@include('includes.js')
@yield('customjs')

</body>
</html>