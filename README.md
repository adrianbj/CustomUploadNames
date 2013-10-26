Rename Uploads
==============

ProcessWire module to automatically rename file (including image) uploads according to a configurable format

###NOTE: This module is still in alpha status so please don't use in production. 

####It requires the lates dev version of PW (19th Oct 2013)
####You currently also have to make Pagefile::setFilename hookable.

###Renaming Rules
The module config allows you to set an unlimited number of Rename Rules. They are processed in order, so you should put more specific rules before more general ones.

You can define rules to specific fields, templates, pages, and file extensions.

The Filename Format can be defined using plain text and PW $page variable, for example: mysite-{$page->path}


###Acknowledgments
The module config settings make use of code from Pete's EmailToPage module and the renaming function is based on this code from Ryan:
http://processwire.com/talk/topic/3299-ability-to-define-convention-for-image-and-file-upload-names/?p=32623
