I created this script because I wasn’t happy with the native Roboform importer inside Bitwarden. This fixed multiple problems including:
* Ignoring MatchUrls
* Parent folders weren’t created if they had no items in them (e.x. if I had identities in Financial/Banks, but nothing in /Financial, then it wouldn’t be created /Financial)
* Completely ignoring the extra fields (RfFieldsV2)

<br>This fixes all those problems.

<br>This needs to be ran via php in a command line interface, so `php convert.php`. It requires the [bitwarden cli “bw” command](https://bitwarden.com/help/cli/).
There are 2 optional arguments you can pass:
1) The name of the import file. If not given, `./RfExport.csv` will be used
2) The “bw” session token. If not given as a parameter, it can be set directly inside this file on line #4. You get this token by running `bw unlock` (after logging in with `bw login`).

<br>This script does the following:
* Reads from a csv file exported by Roboform to import into bitwarden
* Runs `bw sync` before processing
* Imported Fields:
  * Name: Becomes the name of the item
  * Url: Becomes the URL for the item
  * MatchUrl: If this is not the same “Url” then it is added as another URL for the item
  * Login: Becomes the login (user) name
  * Pwd: Becomes the password
  * Note: Becomes the note
  * Folder: Item is put in this folder
  * RfFieldsV2 (multiple):
    * Fields that match the login or password fields are marked as “linked fields” to those. If the field names are the following, they are not added as linked fields: *user, username, login, email, userid, user id, user id$, user_email, login_email, user_login, password, passwd, password$, pass, user_password, login_password, pwd, loginpassword*
    * Fields that are different than the login or password are added as appropriate “Custom fields”. Supported types: '', rad, sel, txt, pwd, rck, chk, are
    * Each field has 5 values within it, and 3 of those seem to be different types of names. So as long as they are not duplicates of each other, each name is stored as a seperate field.
* If all fields are blank but “name” and “note” then the item is considered a “Secure Note”
* While reading the csv file for import, errors and warnings are sent to stdout
* After the csv import has complete, it will give a total number of warnings/errors and ask the user if they want to continue
* Creates missing folders (including parents that have no items in them)
* During export to bitwarden:
  * This process can be quite slow since each instance of running “bw” has to do a lot of work
  * Keeps an active count of items processed and the total number of items
  * If duplicates are found (same item name and folder) then the user is prompted for what they want to do. Which is either s=skip or o=overwrite. A capitol of those letters can be given to perform the same action on all subsequent duplicates.
  * Errors are sent to stdout