{if $status == 'ok'}
    <div class="alert alert-success">
        <h3>{l s='Your order is complete.' mod='flutterwavepayment'}</h3>
        <p>
            {l s='Your order reference is:' mod='flutterwavepayment'} <strong>{$reference}</strong>
        </p>
        <p>
            {l s='You will receive a confirmation email shortly.' mod='flutterwavepayment'}
        </p>
    </div>
{else}
    <div class="alert alert-warning">
        <p>{l s='We noticed a problem with your order. If you think this is an error, please contact our customer support.' mod='flutterwavepayment'}</p>
    </div>
{/if}
