# Windows 365 Enterprise — Volledig LAN-integratie via Azure vNet & Site-to-Site VPN

**Doel:** Windows 365 Enterprise Cloud-pc (8 vCPU / 32 GB / 512 GB) als volwaardig
werkstation in het lokale kantoornetwerk plaatsen via een permanente Azure
Site-to-Site VPN-verbinding, gefinancierd met de $2.400 Azure-partnertegoed.

**Geschatte maandelijkse Azure-kosten (VpnGw1):** ~$140–$160/maand gateway +
~$5–10 publiek IP → ruim 14 maanden gedekt door het partnertegoed.

---

## Vereisten & Voorbereiding

| Item | Waarde (aanpassen aan eigen omgeving) |
|------|--------------------------------------|
| Kantoor-LAN IP-range | bijv. `192.168.1.0/24` |
| Kantoor publiek IP | bijv. `203.0.113.45` (statisch, controleer via [whatismyip.com](https://www.whatismyip.com)) |
| Azure-regio | kies dichtstbijzijnde: `West Europe` (Amsterdam) |
| Azure Subscription | Microsoft AI Cloud Partner Program subscription |
| Entra ID Tenant | jouw `*.onmicrosoft.com` tenant |

**Controleer vóór start:**
- Kantoorrouter ondersteunt IPsec/IKEv2 Site-to-Site VPN (Cisco, Fortinet, pfSense,
  Mikrotik, Ubiquiti USG/UDM, etc.)
- Je hebt `Global Administrator` + `Intune Administrator` rechten in Entra ID
- Windows 365 Enterprise-licentie is toegewezen aan een gebruiker

---

## Fase 1 — Azure Netwerk Infrastructuur (vNet & Gateway)

### 1.1 Resourcegroep aanmaken

1. Ga naar **portal.azure.com** → zoek `Resource groups` → **+ Create**
2. Instellingen:
   - **Resource group name:** `rg-win365-network`
   - **Region:** `West Europe`
3. Klik **Review + create** → **Create**

### 1.2 Virtual Network aanmaken

> **Kritisch:** De Azure vNet-range mag NIET overlappen met je kantoor-LAN.
> Gebruik een aparte RFC-1918 range.

1. Zoek `Virtual networks` → **+ Create**
2. **Basics:**
   - Resource group: `rg-win365-network`
   - Name: `vnet-win365`
   - Region: `West Europe`
3. **IP Addresses** tab — verwijder de standaard range en stel in:
   ```
   Address space:  10.20.0.0/16
   ```
4. Voeg de volgende subnets toe:

   | Subnet naam        | Address range    | Doel                          |
   |--------------------|-----------------|-------------------------------|
   | `GatewaySubnet`    | `10.20.0.0/27`  | VERPLICHT voor VPN Gateway    |
   | `snet-cloudpc`     | `10.20.1.0/24`  | Windows 365 Cloud-pc's        |

   > De subnet genaamd `GatewaySubnet` is exact zo gespeld (hoofdlettergevoelig).
   > Azure reserveert dit automatisch voor de gateway. Maak hem minimaal `/27`
   > (Microsoft vereist minimaal `/29`, maar `/27` is de best practice).

5. Tab **Security**: laat standaard staan (geen BastionHost/Firewall nodig nu)
6. **Review + create** → **Create**

### 1.3 Public IP-adres voor de VPN Gateway aanmaken

1. Zoek `Public IP addresses` → **+ Create**
2. Instellingen:
   - Resource group: `rg-win365-network`
   - Name: `pip-vpngw-win365`
   - SKU: **Standard** (verplicht voor VpnGw1 en hoger)
   - Tier: **Regional**
   - Assignment: **Static**
   - DNS name label: optioneel, bijv. `vpn-win365`
3. **Review + create** → **Create**
4. Na aanmaak: noteer het **IP-adres** (dit heb je nodig voor je kantoorrouter)

### 1.4 Virtual Network Gateway aanmaken (VPN Gateway)

> **SKU-advies: `VpnGw1`**
> - Ondersteunt IKEv2, BGP, meerdere tunnels
> - ~$140/maand → past ruim binnen het $2.400-budget (>14 maanden)
> - `Basic` SKU is goedkoper (~$27/maand) maar ondersteunt géén IKEv2 en is
>   NIET compatibel met Windows 365 ANC → gebruik die NIET

1. Zoek `Virtual network gateways` → **+ Create**
2. Instellingen:

   | Veld | Waarde |
   |------|--------|
   | Name | `vpngw-win365` |
   | Region | `West Europe` |
   | Gateway type | **VPN** |
   | VPN type | **Route-based** (verplicht voor IKEv2 & Windows 365) |
   | SKU | **VpnGw1** |
   | Generation | **Generation1** |
   | Virtual network | `vnet-win365` |
   | Gateway subnet address range | `10.20.0.0/27` (automatisch geselecteerd) |
   | Public IP address | `pip-vpngw-win365` |
   | Active-active mode | **Disabled** (niet nodig voor single-site) |
   | Configure BGP | **Disabled** (niet nodig voor basic setup) |

3. **Review + create** → **Create**

   > **Wacht 30–45 minuten.** De aanmaak van een VPN Gateway duurt lang.
   > Ga ondertussen verder met stap 1.5.

### 1.5 Local Network Gateway aanmaken (vertegenwoordigt jouw kantoor)

1. Zoek `Local network gateways` → **+ Create**
2. Instellingen:

   | Veld | Waarde |
   |------|--------|
   | Name | `lng-kantoor` |
   | Region | `West Europe` |
   | Endpoint | **IP address** |
   | IP address | `203.0.113.45` ← jouw kantoor publiek IP |
   | Address space(s) | `192.168.1.0/24` ← jouw kantoor-LAN range |

   > Heb je meerdere lokale subnetten (bijv. ook een management-VLAN `10.0.10.0/24`)?
   > Voeg ze allemaal toe als extra regels bij "Address space".

3. **Review + create** → **Create**

---

## Fase 2 — Site-to-Site VPN Koppeling

### 2.1 VPN Connection aanmaken in Azure

> Wacht tot de VPN Gateway uit fase 1.4 volledig is aangemaakt (status: `Succeeded`).

1. Open de VPN Gateway `vpngw-win365` → menu **Connections** → **+ Add**
2. Instellingen:

   | Veld | Waarde |
   |------|--------|
   | Name | `conn-s2s-kantoor` |
   | Connection type | **Site-to-site (IPsec)** |
   | Virtual network gateway | `vpngw-win365` |
   | Local network gateway | `lng-kantoor` |
   | Shared key (PSK) | Genereer een sterk wachtwoord (min. 32 tekens, gebruik `openssl rand -base64 32`) |
   | IKE Protocol | **IKEv2** |
   | Use Azure Private IP | Disabled |
   | Enable BGP | Disabled |
   | FastPath | Disabled |

3. Tab **IPsec / IKE policy** — stel **Custom** in met deze parameters:

   | Parameter | Waarde |
   |-----------|--------|
   | IKE protocol | `IKEv2` |
   | Encryption (IKE phase 1) | `AES256` |
   | Integrity (IKE phase 1) | `SHA256` |
   | DH Group | `DHGroup14` |
   | Encryption (IPsec phase 2) | `AES256` |
   | Integrity (IPsec phase 2) | `SHA256` |
   | PFS Group | `PFS14` |
   | SA Lifetime (seconds) | `27000` |
   | SA Lifetime (KB) | `0` (disabled) |

   > Deze policy is expliciet compatibel met pfSense, Fortinet, Mikrotik en
   > Cisco IOS. Pas zo nodig aan op basis van jouw router-documentatie.

4. **OK** → **Create**

### 2.2 Lokale kantoorrouter configureren

> De exacte stappen hangen af van je router-merk. Hieronder de universele
> IPsec/IKEv2-parameters die je moet invullen.

**Verzamel eerst:**
- Azure Gateway publiek IP: het IP van `pip-vpngw-win365` (zie Azure Portal)
- Pre-shared key: de PSK die je in stap 2.1 hebt aangemaakt
- Azure vNet range: `10.20.0.0/16`
- Lokaal subnet: `192.168.1.0/24`

**Universele VPN-instellingen voor je router:**

```
VPN Type:           Site-to-Site / IPsec
Remote Gateway IP:  <Azure pip-vpngw-win365 IP>
Pre-Shared Key:     <jouw PSK>

--- IKE Phase 1 ---
Version:            IKEv2
Encryption:         AES-256
Hash/Integrity:     SHA-256
DH Group:           14 (2048-bit MODP)
Lifetime:           28800 seconden

--- IPsec Phase 2 ---
Encryption:         AES-256
Hash/Integrity:     SHA-256
PFS:                Group 14
Lifetime:           27000 seconden

--- Traffic Selectors ---
Local network:      192.168.1.0/24  (jouw LAN)
Remote network:     10.20.0.0/16    (Azure vNet)
```

**Router-specifieke tips:**

| Router | Waar te configureren |
|--------|---------------------|
| **pfSense/OPNsense** | VPN → IPsec → Tunnels → + Add P1 + P2 |
| **Fortinet FortiGate** | VPN → IPsec Wizard → Site-to-Site |
| **Mikrotik RouterOS** | IP → IPsec → Profiles/Peers/Proposals/Policies |
| **Ubiquiti UDM/USG** | Settings → VPN → Site-to-Site VPN |
| **Cisco IOS** | `crypto ikev2 proposal` + `crypto map` configuratie |

**Firewall-poorten die open moeten zijn op de kantoor-router:**

```
UDP 500   (IKE)
UDP 4500  (IKE NAT-traversal)
Protocol 50 (ESP — Encapsulating Security Payload)
```

### 2.3 VPN-verbinding verifiëren

1. Azure Portal → `conn-s2s-kantoor` → status moet **Connected** worden
2. Test vanuit een apparaat op het kantoor-LAN:
   ```
   ping 10.20.1.1    # Azure GatewaySubnet gateway
   ```
3. In Azure Portal → VPN Gateway → **Metrics** → bekijk `Tunnel ingress/egress bytes`

---

## Fase 3 — DNS & Entra ID Configuratie

### 3.1 DNS-servers instellen op het Azure vNet

Standaard gebruikt Azure vNet de Azure DNS (`168.63.129.16`). Voor LAN-naamsresolutie
moet de Cloud-pc jouw lokale DNS-server kunnen bereiken.

1. Open `vnet-win365` → menu **DNS servers**
2. Kies **Custom** en voer in:
   ```
   DNS server 1:  192.168.1.x    ← IP van jouw lokale DNS/AD-server
   DNS server 2:  168.63.129.16  ← Azure fallback DNS
   ```
3. **Save**
4. Alle Cloud-pc's in dit vNet krijgen nu automatisch deze DNS via DHCP.

> Als je geen lokale DNS-server hebt maar wel een router die DNS doorstuurt:
> vul het IP van je router in als DNS server 1.

### 3.2 Entra ID Join vs. Hybrid Entra ID Join — Keuze

| Scenario | Wanneer kiezen |
|----------|----------------|
| **Entra ID Join** (modern, aanbevolen) | Je beheert alles via Intune/Entra ID, geen on-premises AD nodig. Eenvoudigste opzet. |
| **Hybrid Entra ID Join** | Je hebt een on-premises Active Directory (AD DS) en wil de Cloud-pc domain-joinen. Vereist Azure AD Connect + line-of-sight naar domeincontroller via VPN. |

**Aanbeveling voor jouw situatie (enkel gebruiker, partner-tenant):**
Kies **Entra ID Join** — eenvoudiger, geen AD DS vereist, volledig Intune-beheerd.

### 3.3 (Optioneel) Hybrid Join — Azure AD Connect instellen

Als je toch een on-premises AD hebt:

1. Download **Azure AD Connect** op je lokale server
2. Installeer met **Express Settings** (of Custom voor specifieke OU-filtering)
3. Synchroniseer je AD-gebruikers naar Entra ID
4. Zorg dat de domeincontroller bereikbaar is via het VPN (`192.168.1.x`)
5. De Cloud-pc kan dan automatisch domain-joinen tijdens provisioning

### 3.4 UPN en licentie controleren

1. Entra ID Portal → **Users** → selecteer de doelgebruiker
2. Controleer:
   - **UPN** is een verified custom domain of `@*.onmicrosoft.com`
   - **Licenses**: Windows 365 Enterprise-licentie is toegewezen
   - **MFA** is ingeschakeld (vereist voor Windows 365 provisioning)

---

## Fase 4 — Windows 365 Enterprise & Intune Integratie

### 4.1 Azure Network Connection (ANC) aanmaken

De ANC vertelt Windows 365 via welk Azure-netwerk de Cloud-pc moet worden
uitgerold. Dit is de koppeling tussen Intune en jouw vNet/subnet.

1. Ga naar **intune.microsoft.com** → **Devices** → **Windows 365** →
   **Azure network connections** → **+ Create**

2. **Basics:**

   | Veld | Waarde |
   |------|--------|
   | Network type | **Azure Active Directory join** (of Hybrid als je AD DS hebt) |
   | Name | `anc-win365-kantoor` |

3. **Network details:**

   | Veld | Waarde |
   |------|--------|
   | Subscription | jouw Azure Partner subscription |
   | Resource group | `rg-win365-network` |
   | Virtual network | `vnet-win365` |
   | Subnet | `snet-cloudpc` |

4. (Alleen bij Hybrid Join) **AD domain:**

   | Veld | Waarde |
   |------|--------|
   | AD DNS domain name | `kantoor.local` of `kantoor.com` |
   | Organizational unit (OU) | `OU=CloudPCs,DC=kantoor,DC=local` |
   | AD username (UPN) | `join-account@kantoor.local` |
   | AD password | wachtwoord van het domain join-account |

5. **Review + create** → **Create**

### 4.2 Automatische Intune Health Checks

Na het aanmaken voert Microsoft automatisch de volgende tests uit op de ANC
(dit duurt ~5–10 minuten):

| Test | Wat wordt gecontroleerd |
|------|------------------------|
| **Azure tenant readiness** | Entra ID tenant is correct geconfigureerd |
| **Azure virtual network readiness** | vNet en subnet bestaan en hebben beschikbare IP's |
| **Azure subnet IP address usage** | Voldoende beschikbare IP-adressen in `snet-cloudpc` |
| **Azure vNet DNS resolution** | DNS-servers zijn bereikbaar en resolven namen |
| **Azure vNet connectivity** | Uitgaande internet-connectiviteit vanuit het vNet |
| **Endpoint connectivity** | Bereikbaarheid van Windows 365 service-endpoints |
| **Entra ID device sync** | Apparaten kunnen worden geregistreerd in Entra ID |
| **Intune enrollment** | Automatische enrollment via Intune werkt |
| **Hybrid domain join** | (alleen Hybrid) Domeincontroller bereikbaar via VPN |
| **First party app permissions** | Juiste Azure RBAC-machtigingen voor Windows 365 |

> Als een test faalt, klik dan op de test voor details. Veelvoorkomende oorzaken:
> - DNS niet bereikbaar → controleer firewall-regels op je router
> - Subnet heeft geen beschikbaar IP → vergroot `snet-cloudpc` of check of er
>   bestaande resources in zitten
> - Endpoint connectivity faalt → zie stap 4.3

### 4.3 Vereiste Azure service-endpoints / firewall-regels

Windows 365 vereist uitgaande HTTPS-connectiviteit vanuit het `snet-cloudpc` subnet
naar de volgende Microsoft-domeinen:

```
*.manage.microsoft.com
*.microsoftonline.com
*.windows.net
*.windowsazure.com
*.azure.net
*.azureedge.net
*.microsoft.com
login.microsoftonline.com
*.wvd.microsoft.com
```

Als je kantoor-firewall het internet-verkeer vanuit Azure filtert:
- Voeg bovenstaande domeinen toe als **uitgaande HTTPS (443) allow-rules**
- Of maak een **Network Security Group (NSG)** aan op `snet-cloudpc` met
  outbound-rules voor deze endpoints

### 4.4 Intune Provisioning Policy aanmaken en koppelen

1. Ga naar **intune.microsoft.com** → **Devices** → **Windows 365** →
   **Provisioning policies** → **+ Create policy**

2. **General:**

   | Veld | Waarde |
   |------|--------|
   | Name | `policy-win365-kantoor` |
   | Join type | **Entra ID join** (of Hybrid) |
   | Network | `anc-win365-kantoor` ← jouw ANC uit stap 4.1 |
   | Windows autopatch | Optioneel inschakelen |

3. **Image:**

   | Veld | Waarde |
   |------|--------|
   | Image type | **Gallery image** |
   | Image | `Windows 11 Enterprise + Microsoft 365 Apps (latest)` |

   > Je kunt ook een **custom image** uploaden als je extra software wilt
   > pre-installeren (bijv. VPN clients, admin tools).

4. **Configuration:**
   - **Language & region:** Dutch (Netherlands)
   - **Microsoft Endpoint Manager:** Auto-enrollment ingeschakeld
   - **Assignments:** wijs toe aan een **Entra ID groep** die de doelgebruiker bevat

5. **Assignments tab:**
   - **Assign to:** Groups
   - Klik **+ Add groups** → selecteer de groep of de directe gebruiker
   - Klik **Next** → **Create**

### 4.5 Cloud-pc provisioning starten & verifiëren

Na het opslaan van de Provisioning Policy:

1. Provisioning start automatisch voor alle gebruikers in de toegewezen groep
2. Volg de voortgang via: **Devices** → **Windows 365** → **All Cloud PCs**
3. Status doorloopt: `Provisioning` → `Provisioning in progress` → `Provisioned`

   > Dit duurt normaal **20–45 minuten** bij eerste provisioning.

4. Na `Provisioned`:
   - De Cloud-pc heeft een IP-adres in `10.20.1.x`
   - DNS verwijst naar jouw lokale server
   - Via het VPN kan de Cloud-pc communiceren met `192.168.1.x` apparaten

5. Verificatie vanuit de Cloud-pc (open via **windows365.microsoft.com**):
   ```
   # Netwerkconfiguratie controleren
   ipconfig /all

   # DNS-resolutie testen (vervangt 'server' door een lokale hostname)
   nslookup nas.kantoor.local

   # Ping naar een lokaal apparaat (bijv. NAS)
   ping 192.168.1.50

   # Traceroute naar lokaal netwerk
   tracert 192.168.1.1
   ```

---

## Fase 5 — Beveiliging & Beheer (Aanbevolen)

### 5.1 Network Security Group (NSG) op het CloudPC-subnet

1. Zoek `Network security groups` → **+ Create**
2. Naam: `nsg-snet-cloudpc`, regio: `West Europe`
3. Koppel aan `snet-cloudpc` via **Subnets** → **+ Associate**
4. Voeg **inbound rules** toe:

   | Prioriteit | Bron | Doel | Poort | Actie | Naam |
   |-----------|------|------|-------|-------|------|
   | 100 | `192.168.1.0/24` (LAN) | Any | Any | Allow | Allow-LAN-inbound |
   | 110 | `10.20.0.0/16` (vNet) | Any | Any | Allow | Allow-vNet-inbound |
   | 4096 | Any | Any | Any | Deny | Deny-all-inbound |

5. **Outbound rules:**

   | Prioriteit | Bron | Doel | Poort | Actie | Naam |
   |-----------|------|------|-------|-------|------|
   | 100 | Any | `192.168.1.0/24` | Any | Allow | Allow-LAN-outbound |
   | 110 | Any | `AzureCloud` (service tag) | 443 | Allow | Allow-Azure-HTTPS |
   | 120 | Any | Internet | 443,80 | Allow | Allow-Internet-outbound |

### 5.2 Intune Compliance Policy instellen

1. **Devices** → **Compliance policies** → **+ Create policy** → Windows 10/11
2. Aanbevolen instellingen:
   - BitLocker: **Required**
   - Firewall: **Required**
   - Antivirus: **Required**
   - Minimum OS version: `10.0.22621` (Windows 11 22H2)
3. Assign aan dezelfde groep als de Provisioning Policy

### 5.3 Conditional Access Policy

1. **Entra ID** → **Security** → **Conditional Access** → **+ New policy**
2. Naam: `CA-Win365-MFA`
3. Assignments:
   - Users: doelgebruiker(s)
   - Cloud apps: **Windows 365**
4. Grant: **Require MFA** + **Require Hybrid Azure AD joined device** (of compliant device)

---

## Budget Overzicht

| Resource | SKU | Geschatte kosten/maand |
|----------|-----|------------------------|
| VPN Gateway | VpnGw1 | ~$140 |
| Public IP (Standard Static) | Standard | ~$4 |
| vNet data transfer (binnen regio) | Inbound gratis | ~$0–5 |
| **Totaal** | | **~$144–150/maand** |

**$2.400 partnertegoed ÷ $150/maand ≈ 16 maanden gedekt**

De Windows 365 Enterprise-licentie zelf betaal je via de partnerprogramma-toewijzing
(niet vanuit het Azure-tegoed).

---

## Troubleshooting Referentie

| Probleem | Oplossing |
|----------|-----------|
| ANC health check "DNS resolution failed" | Controleer of de DNS-server bereikbaar is via het VPN; tijdelijk `168.63.129.16` als enige DNS testen |
| ANC health check "Endpoint connectivity" faalt | Controleer NSG-outbound rules en router-firewall op HTTPS-blocking |
| VPN status blijft "Not connected" | Controleer PSK, IP-ranges, en of UDP 500/4500 open zijn op kantoor-firewall |
| Cloud-pc kan LAN niet pingen | Controleer Local Network Gateway address spaces; voeg eventuele ontbrekende subnets toe |
| Provisioning stuck op "Provisioning in progress" | Controleer ANC health checks opnieuw; herstart via "Retry" in Intune |
| Hybrid join "Domain controller unreachable" | Verifieer VPN-tunnel is up; ping DC vanuit Azure VM ter test |

---

*Bijgewerkt: 2026-05-14 | Architectuur: Entra ID Join + VpnGw1 + IKEv2 | Budget: $2.400 Azure-partnertegoed*
