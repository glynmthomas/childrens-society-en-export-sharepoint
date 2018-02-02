# Children's Society Engaging Networks data export
Script to download data exports from Engaging Networks and upload to an SFTP server, which can be run manually on-demand or set up to run regularly e.g. daily.

It is set up for PHP 5.3.3.

## Installation and setup
**1. Log into your server** using SSH.

**2. Clone this repository** onto your server:

`git clone git@github.com:joefhall/childrens-society-en-export.git`

or

`git clone https://github.com/joefhall/childrens-society-en-export.git`

**3. Install the packages it relies on** using composer, in the same directory where you cloned it:

`composer install`

(If you don't have composer, you can [get it here](https://getcomposer.org/))

**4. Create a `.env` file** which will hold your environment variables (some of which are sensitive):

`cp .env.example .env`

**5. Check and add variables** into the `.env` file:

`vi .env`

Any value which has spaces needs "double quotes" around it
Save the file and exit (`:x` if you're using Vi as your text editor)

**6. Test it's working** by going into the `src` directory and running the script:

`cd src`

`php script.php`

Having PHP problems?  Check your version with `php -v` - it's set up for 5.3.3.
Having any other problems?  More detailed errors are saved in the log file `error-log.txt` in the main directory.

**7. Set up a scheduled cron job:**

`crontab -e` or `sudo crontab -e`

then in the text editor that comes up, add something like this:

`0 2 * * * cd /root/engaging-networks-export/ && php src/script.php`

This example runs daily at 2am, [see more examples.](https://tecadmin.net/crontab-in-linux-with-20-examples-of-cron-schedule/)  Change the directory location after `cd` to wherever you cloned the repository.
