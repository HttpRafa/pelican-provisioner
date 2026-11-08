<?php

return [
    'user.provisioner.quota' => '<b>:actor</b> changed the quota from :old_cpu CPU, :old_memory memory, :old_disk disk, :old_servers servers to :new_cpu CPU, :new_memory memory, :new_disk disk, :new_servers servers.',
    'user.provisioner.quota_removed' => '<b>:actor</b> removed the quota override. It was :old_cpu CPU, :old_memory memory, :old_disk disk, :old_servers servers. The account now uses :new_cpu CPU, :new_memory memory, :new_disk disk, :new_servers servers.',
    'user.provisioner.quota_access' => '<b>:actor</b> changed which eggs and nodes the quota allows. Eggs: :eggs. Nodes: :nodes.',
    'user.provisioner.quota_ban' => '<b>:actor</b> blocked this account from creating, resizing, and deleting servers.',
    'user.provisioner.quota_unban' => '<b>:actor</b> allowed this account to use its quota again.',
];
