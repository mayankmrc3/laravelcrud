<div class="container">
    <div class="row">
        <div class="flex-center position-ref full-height">
            <div class="panel panel-default">
                <div class="panel-heading">Worldpay Demo</div>
            <div class="panel-body">
                <div class="content">
                    <form class="form-horizontal" action="{{route ('checkouts')}}" method="post">
                        <div class="header">Checkout</div>
                        {{csrf_field()}}
                        <div class="form-group">
                            <label for="name" class="col-md-4 control-label">Direct Order?</label> 
                            <div class="col-md-6">
                                <select id="direct-order" name="direct-order">
                                    <option value="1" selected="">Yes</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="email" class="col-md-4 control-label">Order Type</label>
                            <div class="col-md-6">
                                <select id="order-type" name="order-type">
                                    <option value="ECOM" selected="">ECOM</option>
                                    <option value="MOTO">MOTO</option>
                                    <option value="RECURRING">RECURRING</option>
                                    <!-- <option value="APM">APM</option> -->
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Name</label>
                            <div class="col-md-6">
                                <input id="name" name="name" data-worldpay="name" value="Name" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Card Number</label>
                            <div class="col-md-6">
                                <input id="card" size="20" data-worldpay="number" value="4444333322221111" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">CVC</label>
                            <div class="col-md-6">
                                <input id="cvc" size="4" data-worldpay="cvc" value="321" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Expiration (MM/YYYY)</label>
                            <div class="col-md-6">
                                <select id="expiration-month" data-worldpay="exp-month">
                                    <option value="01">01</option>
                                    <option value="02">02</option>
                                    <option value="03">03</option>
                                    <option value="04">04</option>
                                    <option value="05">05</option>
                                    <option value="06">06</option>
                                    <option value="07">07</option>
                                    <option value="08">08</option>
                                    <option value="09">09</option>
                                    <option value="10" selected="selected">10</option>
                                    <option value="11">11</option>
                                    <option value="12">12</option>
                                </select><span> / </span>
                                <select id="expiration-year" data-worldpay="exp-year">
                                    <option value="2016">2016</option>
                                    <option value="2017">2017</option>
                                    <option value="2018">2018</option>
                                    <option value="2019">2019</option>
                                    <option value="2020">2020</option>
                                    <option value="2021">2021</option>
                                    <option value="2022" selected="selected">2022</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Amount</label>
                            <div class="col-md-6">
                                <input id="amount" size="4" name="amount" value="15.23" type="text"/>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Currency</label>
                            <div class="col-md-6">
                                <input id="currency" name="currency" value="GBP" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Reusable Token</label>
                            <div class="col-md-6">
                                <input id="chkReusable" name="chkReusable" type="checkbox">
                            </div>
                        </div>
                    
                        <div class="header">Billing address</div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label"> Address 1</label>
                            <div class="col-md-6">
                                <input id="address1" name="address1" value="123 House Road" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label"> Address 2</label>
                            <div class="col-md-6">
                                <input id="address2" name="address2" value="123 House Road" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label"> Address 3</label>
                            <div class="col-md-6">
                                <input id="address3" name="address3" value="jgjgj jh" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">City</label>
                            <div class="col-md-6">
                                <input id="city" name="city" value="London" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">State</label>
                            <div class="col-md-6">
                                <input id="state" name="state" value="London" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Postcode</label>
                            <div class="col-md-6">
                                <input id="postcode" name="postcode" value="EC1 1AA" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Country Code</label>
                            <div class="col-md-6">
                                <input id="country-code" name="countryCode" value="GB" type="text">
                            </div>
                        </div>
                        <div class="header">Other</div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Order Description</label>
                            <div class="col-md-6">
                                <input id="description" name="description" value="My test order" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password" class="col-md-4 control-label">Customer Order Code</label>
                            <div class="col-md-6">
                                <input id="customer-order-code" name="customer-order-code" value="A123" type="text">
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="col-md-6 pull-right"><input id="place-order" value="Place Order" type="submit"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>