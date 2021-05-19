{if $error_message}
    <h3>Error</h3>

    <p>{$error_message}</p>
{else}
    <h3>Success!</h3>

    <p>
        You have verified that your OpenStreetMap username is <strong>"{$osm_username}"</strong>.
        {if $membership_status eq 'Pending'}
            Your membership is pending.
        {elseif $membership_status eq 'New'}
            Your membership has been activated.
        {/if}
    </p>
{/if}