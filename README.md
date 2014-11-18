Rename Uploads
==============

ProcessWire module to automatically rename file (including image) uploads according to a configurable format

This module lets you define as many rules as you need to determine how uploaded files will be named and you can have different rules for different pages, templates, fields, and file extensions, or one rule for all uploads.
Renaming works for files uploaded via the admin interface and also via the API, including images added from remote URLs.

###Renaming Rules
* The module config allows you to set an unlimited number of Rename Rules.
* You can define rules to specific fields, templates, pages, and file extensions.
* If a rule option is left blank, the rule with be applied to all fields/templates/pages/extensions.
* Leave Filename Format blank to prevent renaming for a specific field/template/page combo, overriding a more general rule.
* Rules are processed in order, so put more specific rules before more general ones. You can drag to change the order of rules as needed.
* The following variables can be used in the filename format: $page, $template, $field, and $file. For some of these (eg. $field->description), if they haven't been filled out and saved prior to uploading the image, renaming won't occur on upload, but will happen on page save - if you inserted it into an RTE/HTML field before page save, then the link will be automatically updated). Some examples:
  * $page->title
  * mysite-{$template->name}-images
  * $field->label
  * $file->description
  * {$page->name}-{$file->filesize}-kb
  * prefix-[Y-m-d_H-i-s]-suffix (anything inside square brackets is is considered to be a PHP date format for the current date/time)
  * randstring\[n\] (where n is the number of characters you want in the string)
  * ### (custom number mask, eg. 001 if more than one image with same name on a page. This is an enhanced version of the automatic addition of numbers if required)
* If 'Rename on Save' is checked files will be renamed again each time a page is saved (admin or front-end via API). WARNING: this setting will break any direct links to the old filename in your template files. However, images inserted into RTE/HTML fields on the same page will have their links automatically updated.
* The Filename Format can be defined using plain text and PW $page variable, for example: mysite-{$page->path}
* You can preserve the uploaded filename for certain rules. This will allow you to set a general renaming rule for your entire site, but then add a rule for a specific page/template/field that does not rename the uploaded file. Just simply build the rule, but leave the Filename Format field empty.
* You can specify an optional character limit (to nearest whole word) for the length of the filename - useful if you are using $page->path, $path->name etc and have very long page names - eg. news articles, publication titles etc.

###Acknowledgments
The module config settings make use of code from Pete's EmailToPage module and the renaming function is based on this code from Ryan:
http://processwire.com/talk/topic/3299-ability-to-define-convention-for-image-and-file-upload-names/?p=32623

####Support forum:
http://processwire.com/talk/topic/4865-custom-upload-names/


## License

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

(See included LICENSE file for full license text.)