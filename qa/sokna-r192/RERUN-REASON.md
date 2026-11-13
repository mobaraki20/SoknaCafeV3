# R192 isolated MariaDB rerun

The initial CI failed before checkout because Docker health-cmd quoting was incompatible with the current hosted runner. The revised workflow waits on an authenticated PDO connection and retains the same byte-pinned R189 source.
