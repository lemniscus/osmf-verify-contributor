<h3>Success!</h3>

<p>
    You have verified that your OpenStreetMap username is "{$osm_username}".
    {if $membership_status eq 'Pending'}
        Your membership is pending.
    {elseif $membership_status eq 'New'}
        Your membership has been activated.
    {/if}
</p>