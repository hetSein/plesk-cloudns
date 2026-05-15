# plesk-cloudns
Automagically adds/removes slave DNS zones in ClouDNS when events occur in Plesk

# What it does
This project allows simple **slave** zone creation/deletion in [ClouDNS](https://www.cloudns.net) for all created/deleted domains in a [Plesk Onyx](https://www.plesk.com/) installation. Every time a domain is created (or deleted) in Plesk, the relevant slave zone is created (or deleted) in ClouDNS.

After successful setup, Plesk DNS can be blocked in the firewall for the wider internet, preventing numerous attack vectors on your servers, and zone creation is event based instead of cron.

# What it does not
This project will not configure ClouDNS or Plesk to work together (see docs below though). 
It does not have a graphic interface - you need to edit the `ClouDNS.php` file provided and upload it to a specific path as root.

# Assumptions
To be able to use this project, several assumptions are made regarding your setup:

- You have Plesk Onyx (untested in previous versions, should work).
- You have SSH access to your server as root (or can sudo).
- You have PHP with the `curl` module enabled in your server CLI (use `php -m | grep curl` to check in an SSH session, should be default)
- You want to use ClouDNS as a slave, and Plesk as a master for all domains created after installation
  - This means that DNS records are managed in Plesk, and are mirrored in ClouDNS

# Installation

  * Login to ClouDNS, and create an API user. You should have an ***auth-id*** and ***password***.
  * Place the `ClouDNS.php` file in the `/usr/local/psa/admin/plib/registry/EventListener/` directory
  * Edit the file and add ***auth_id*** and ***password*** in the relevant sections
  * (Optional) Define which IP should be the master IP that ClouDNS talks to. If left blank, the script will try to determine it automagically.
  * Enjoy!

# Syncing existing domains

The EventListener only handles domains created **after** installation. To sync
domains that already exist in Plesk, use the included `sync.php` script.

## Prerequisites

You need a Plesk API key. Create one under **Tools & Settings → API Keys** in the
Plesk admin panel. The key needs at minimum read access to domains.

## Usage

1. Open `sync.php` and fill in the four configuration variables at the top:

   ```php
   $pleskHost   = 'plesk.example.com';  // your Plesk hostname or IP
   $pleskApiKey = 'YOUR_API_KEY';       // Plesk API key
   $clouDnsAuthId  = '12345';           // ClouDNS auth-id
   $clouDnsAuthKey = 'secret';          // ClouDNS auth-password
   $masterIp    = '1.2.3.4';           // (optional) master IP for zone transfers
   ```

2. Do a dry run to verify which domains will be synced:

   ```bash
   php sync.php --dry-run
   ```

3. Run for real:

   ```bash
   php sync.php
   ```

## PleskClient

`PleskClient.php` is a standalone Plesk REST API client (Plesk 17.8+). It can
be reused in your own scripts:

```php
require_once 'PleskClient.php';

$plesk = new PleskClient('plesk.example.com', 'YOUR_API_KEY');

if ($plesk->testConnection()) {
    $domains = $plesk->getDomains(); // returns array of domain name strings
}
```
  
# Basic troubleshooting
- In ClouDNS, your API user has **ALL** (read: both IPv4 and IPv6) IPs allowed, not just your primary.
- In Plesk, you allow ClouDNS to transfer zones as slave under Tools & Settings > General Settings > DNS Template > Transfer Restrictions Template. **ALL** your listed nameservers should be there (both IPv4 and IPv6), in order to properly propagate records.
- Having configured ClouDNS does not mean that the records are actually used if the 

# Credits
This script is a modified version of the official [ClouDNS php script](https://github.com/ClouDNS/cloudns-api-bulk-updates/blob/master/plesk-slave-zones-add/plesk-slave-zones-add.php) for the same task. This version is lighter and event based though, but does not implement an exclude list.

