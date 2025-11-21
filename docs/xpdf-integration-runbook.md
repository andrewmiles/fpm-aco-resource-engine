# Xpdf Integration Runbook (Milestone 15 - Search & Filters)

Context: enable SearchWP to index PDF body content for the `resources` engine using the official Xpdf Integration extension. This runbook assumes a Linux/cPanel host where the WordPress document root is `~/public_html`.

## 1. Preconditions

- SearchWP Pro installed and active.
- SearchWP Xpdf Integration extension installed and active.
- The Media source and "Document Content" field are enabled on the `resources` SearchWP engine.
- Local media is retained (WP Offload Media "Remove Local Media" disabled for PDFs).

## 2. Check that `exec()` is allowed

1. SSH to the hosting account:

   ```bash
   ssh acomain@5.134.12.230
   ```

2. From the shell, check `disable_functions`:

   ```bash
   php -i | grep disable_functions
   ```

3. Confirm that `exec` is **not** listed. If it is, Xpdf Integration cannot run; raise a ticket with the host to enable `exec` for this account.

## 3. Install Xpdf CLI tools (server-side)

1. On your Mac, download the "Linux 64-bit" Xpdf tools archive from the official Xpdf site; the file will look like `xpdf-tools-linux-4.xx.tar.gz`.
2. Copy it to the server home directory, replacing the filename as needed:

   ```bash
   scp ~/Downloads/xpdf-tools-linux-4.xx.tar.gz acomain@5.134.12.230:~/
   ```

3. SSH in and prepare a non-public directory:

   ```bash
   ssh acomain@5.134.12.230
   mkdir -p ~/xpdf
   cd ~/xpdf
   tar -xzf ~/xpdf-tools-linux-4.xx.tar.gz
   ```

4. After extraction, you should have a directory like `xpdf-tools-linux-4.xx/` containing `bin32` and `bin64`. Move the CLI binaries into a dedicated `bin` folder and make them executable:

   ```bash
   cd xpdf-tools-linux-4.xx
   mkdir -p ~/xpdf/bin
   cp bin64/pdftotext bin64/pdfinfo ~/xpdf/bin/
   chmod 755 ~/xpdf/bin/pdftotext ~/xpdf/bin/pdfinfo
   ```

5. Confirm they run from the shell:

   ```bash
   ~/xpdf/bin/pdftotext -v
   ~/xpdf/bin/pdfinfo -v
   ```

6. Note the absolute paths; on cPanel this will typically be:

   - `/home/acomain/xpdf/bin/pdftotext`
   - `/home/acomain/xpdf/bin/pdfinfo`

   You can confirm with `pwd` while in `~/xpdf/bin`.

## 4. Wire Xpdf into WordPress (SearchWP)

Add the following to your SearchWP customisations plugin or site plugin (any file that always loads in WordPress):

```php
<?php
// Tell SearchWP where the Xpdf binaries live.
add_filter( 'searchwp_xpdf_path', function () {
    return '/home/acomain/xpdf/bin/pdftotext'; // Absolute path to the binary.
} );

add_filter( 'searchwp_pdfinfo_path', function () {
    return '/home/acomain/xpdf/bin/pdfinfo'; // Absolute path to the binary.
} );
```

Adjust the paths if your home directory or layout differs.

## 5. Rebuild SearchWP index

1. In wp-admin, go to **Settings -> SearchWP**.
2. Open the `resources` engine.
3. Ensure the **Media** source is present and "Document Content" has a non-zero weight.
4. Click **Save Engines** and then **Rebuild Index**.

## 6. Test Xpdf Integration

1. Go to the **Xpdf Integration** screen under SearchWP.
2. Enter the attachment ID of a known PDF and run the test.
3. Verify:
   - Exit code is `0`.
   - Extracted text is readable.
4. Finally, search for a distinctive phrase from that PDF on the front end (Resources scope) and confirm the Resource appears in results.

If any step fails (non-zero exit code or empty text), check file permissions, paths, and `exec()` availability, then re-run the tests.
