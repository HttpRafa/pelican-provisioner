<?php

return [
    'user.provisioner.quota' => '<b>:actor</b> hat das Kontingent von :old_cpu CPU, :old_memory RAM, :old_disk Speicherplatz, :old_servers Server auf :new_cpu CPU, :new_memory RAM, :new_disk Speicherplatz, :new_servers Server geändert.',
    'user.provisioner.quota_removed' => '<b>:actor</b> hat die Kontingent-Ausnahme entfernt. Sie war :old_cpu CPU, :old_memory RAM, :old_disk Speicherplatz, :old_servers Server. Das Konto nutzt jetzt :new_cpu CPU, :new_memory RAM, :new_disk Speicherplatz, :new_servers Server.',
    'user.provisioner.quota_access' => '<b>:actor</b> hat geändert, welche Eggs und Nodes das Kontingent erlaubt. Eggs: :eggs. Nodes: :nodes.',
    'user.provisioner.quota_ban' => '<b>:actor</b> hat diesem Konto das Erstellen, Ändern und Löschen von Servern gesperrt.',
    'user.provisioner.quota_unban' => '<b>:actor</b> hat diesem Konto erlaubt, sein Kontingent wieder zu nutzen.',
];
