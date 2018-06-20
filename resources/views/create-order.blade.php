@extends('layouts.app')

@section('content')

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0, user-scalable=yes">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

    <title>Laravel WorldPay Payment</title>

    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet"/>
    <script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/2.1.4/jquery.min.js"></script>
    <script type="text/javascript" src="https://cdn.worldpay.com/v1/worldpay.js"></script>
    <script>
        $(function () {
            var worldPayKey = "<?php echo config('worldpay.sandbox.client'); ?>";
            var form = document.getElementById('bookingForm');
            Worldpay.useOwnForm({
                'clientKey': worldPayKey,
                'form': form,
                'reusable': true,
                'callback': function (status, response) {
                    $('#paymentErrors').html('');
                    if (response.error) {
                        Worldpay.handleError(form, $('#paymentErrors'), response.error);
                        $('input[type="submit"]').prop('disabled', false);
                    } else {
                        var token = response.token;
                        Worldpay.formBuilder(form, 'input', 'hidden', 'token', token);
                        form.submit();
                    }
                }
            });
        });
    </script>

<form action="<?php echo url('checkout') ?>" method="post"
      id="bookingForm" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" value="<?php echo csrf_token(); ?>" name="_token" id="csrf_token">
    <div class="container">
        <div class="row">
            <h1>Laravel WorldPay Payment</h1>
            <span id="paymentErrors" class="bg-danger"></span>

            <div class="form-group">
                <label>Name on Card</label>
                <input placeholder="Name on Card" name="cardName" value="cardName" data-worldpay="name" type="text"
                       required="required" class="form-control"/>
            </div>
            <div class="form-group">
                <label>Card Number</label>
                <input placeholder="Card Number" type="text" size="20" value="4444333322221111" data-worldpay="number"
                       required="required" class="form-control"/>
            </div>

            <div class="form-group">
                <label>Expiration (MM)</label>
                <input type="text" data-worldpay="exp-month" value="10" required="required" class="form-control"/>
            </div>
            <div class="form-group">
                <label>Expiration (YYYY)</label>
                <input type="text" data-worldpay="exp-year" value="2019" required="required" class="form-control"/>
            </div>
            <div class="form-group">
                <label>CVC</label>
                <input placeholder="CVC" type="text" size="4" value="321" data-worldpay="cvc" required="required"
                       class="form-control"/>
            </div>

            <div class="form-group">
                <input type="submit" class="btn btn-large btn-primary" value="Book Now"/>
            </div>
        </div>
    </div>
</form>


<style>
.header {
    font-weight: bold;
    width: 800px;
    height: 35px;
    margin: 0 auto 20px;
    border-bottom: 1px solid #D0D0D0;
}
</style>