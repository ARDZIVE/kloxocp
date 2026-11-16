<?php
/**
 * Legacy demo form for webcommand. Server-side plan fetch with a blank admin
 * password is disabled (security V4). Mutating actions need api-key from
 * etc/conf/webcommand.key (security W4) plus POST/origin/rate-limit (U1).
 */

include 'lib/html/apilib.php';

api_main();

function api_main()
{
	?>
<p>Add a Kloxo CP Client via <code>webcommand</code>. Mutating calls need the
API key from <code>/root/kloxo-webcommand-key</code> (header
<code>X-Kloxo-Api-Key</code> or field <code>api-key</code>).</p>

<form method="post" action="/bin/webcommand.php">

<input type='hidden' name='login-class' value='client'>
<input type='hidden' name='login-name' value='admin'>
<label>Admin password:<br>
<input type='password' name='login-password' value='' autocomplete='current-password'></label><br>
<label>API key:<br>
<input type='password' name='api-key' value='' autocomplete='off'></label><br>
<input type='hidden' name='action' value='add'>
<input type='hidden' name='class' value='client'>
<input type='hidden' name='v-type' value='customer'>
Client name:<br>
<input type='text' name='name' value=''> <br>
Contact Email:<br>
<input type='text' name='v-contactemail' value=''><br>
Password:<br>
<input type='password' name='v-password' value='' autocomplete='new-password'><br>
<?php

	get_and_print_a_select_variable('Plan', 'resourceplan', 'v-plan_name');
?>

<input type='submit' name='submit' value='submit'><br>

<?php
}
