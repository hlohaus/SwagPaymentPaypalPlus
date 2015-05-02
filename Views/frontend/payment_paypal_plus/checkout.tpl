{block name="frontend_index_header_javascript" append}
    {include file="frontend/payment_paypal_plus/javascript.tpl"}
{/block}

{block name="frontend_checkout_confirm_payment"}
    {if $PaypalPlusApprovalUrl}
        {include file="frontend/payment_paypal_plus/confirm_payment.tpl"}
    {else}
        {$smarty.block.parent}
    {/if}
{/block}

{block name='frontend_checkout_confirm_left_payment_method'}
    {if !$PaypalPlusApprovalUrl || !{config name=paypalHidePaymentSelection}}
        {$smarty.block.parent}
    {/if}
{/block}