# Konfigurace databázového prostředí mikoucto v Azure.
# Zkopírujte jako config.ps1 (je v .gitignore) a upravte hodnoty.

$DbConfig = @{
    # Předplatné Azure (název nebo ID). Povinné, skripty bez něj nepokračují.
    Subscription        = ''

    ResourceGroup       = 'rg-mikoucto-dev'
    Location            = 'northeurope'

    # Globálně unikátní název: malá písmena, číslice a pomlčky, 3-63 znaků.
    # Server bude dostupný jako <ServerName>.postgres.database.azure.com
    ServerName          = 'psql-mikoucto-dev'

    PgVersion           = '17'
    Tier                = 'Burstable'
    SkuName             = 'Standard_B1ms'
    StorageSizeGb       = 32
    BackupRetentionDays = 7

    # Administrátor serveru (nelze později změnit). Nesmí být admin, root, guest, public ani začínat pg_.
    AdminUser           = 'mikoucto_admin'

    DatabaseName        = 'mikoucto'

    Tags                = @{
        projekt   = 'mikoucto'
        prostredi = 'dev'
        vlastnik  = 'jmeno.prijmeni@organizace.cz'
    }

    # Prefix názvů firewall pravidel pro vývojové počítače.
    FirewallRulePrefix  = 'dev'

    # Veřejná IP vývojového počítače. Prázdné = zjistí se automaticky přes https://api.ipify.org
    PublicIpOverride    = ''

    # Verze přenosných binárek PostgreSQL pro psql (https://www.enterprisedb.com/download-postgresql-binaries)
    PgClientVersion     = '17.10-1'
}
