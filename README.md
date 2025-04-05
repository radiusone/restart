### Usage
This module can be used to perform immediate or scheduled restarts of registered phones on the PBX. As a front end for a cron job, scheduling is a bit awkward but very flexible. Some examples:

|  Time  | Month | Weekday | Day | Recurring | Result |
|--------|-------|---------|-----|-----------|--------|
| 3:00am |   *   |    *    |  *  |     ✔     | Reboot every day at 3:00 am |
| 8:00am |   *   |  Sunday |  *  |     ✔     | Reboot every Sunday at 8:00 am |
| 3:00am |   *   |    *    |  3  |     ✔     | Reboot on the third of every month at 3:00 am |
| 6:30pm | March |    *    |  8  |     ✔     | Reboot every year on March 8 at 6:30 pm |
| 4:15pm | March |    *    |  *  |     ✔     | Reboot every day in March at 4:15pm |
| 3:00am |   *   |    *    |  *  |           | Reboot tomorrow at 3:00 am |
| 8:00am |   *   |  Sunday |  *  |           | Reboot next Sunday at 8:00 am |
| 3:00am |   *   |    *    |  3  |           | Reboot on the third of next month at 3:00 am |
| 6:30pm | March |    *    |  8  |           | Reboot on March 8 at 6:30 pm |
| 4:15pm | March |    *    |  *  |           | Reboot on the first of March at 4:15pm |

Custom cron expressions can be entered as well.

### About
This is a module for [FreePBX©](http://www.freepbx.org/ "FreePBX Home Page"). [FreePBX](http://www.freepbx.org/ "FreePBX Home Page") is an open source GUI (graphical user interface) that controls and manages [Asterisk©](http://www.asterisk.org/ "Asterisk Home Page") (PBX). FreePBX is licensed under GPL.
[FreePBX](http://www.freepbx.org/ "FreePBX Home Page") is a completely modular GUI for Asterisk written in PHP and Javascript. Meaning you can easily write any module you can think of and distribute it free of cost to your clients so that they can take advantage of beneficial features in [Asterisk](http://www.asterisk.org/ "Asterisk Home Page")

### License
[This module's code is licensed as GPLv3+](http://www.gnu.org/licenses/gpl-3.0.txt)
