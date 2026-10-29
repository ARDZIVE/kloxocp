<?php
/**
 * Easy Installer was removed (audit C5): it downloaded and unpacked application
 * archives over plain HTTP from hosts the project does not control.
 */
fwrite(STDERR, "Easy Installer is disabled and will not download software.\n");
exit(1);
