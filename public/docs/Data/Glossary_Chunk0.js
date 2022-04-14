define({'access level':{d:'A permission assigned to a user to control which commands the user can execute. See also the \u0027protections\u0027 entry in this glossary and the \u0027p4 protect\u0027 command in the P4 Command Reference.',l:''},'admin access':{d:'An access level that gives the user permission to privileged commands, usually super privileges.',l:''},'APC':{d:'The Alternative PHP Cache, a free, open, and robust framework for caching and optimizing PHP intermediate code.',l:''},'archive':{d:'1. For replication, versioned files (as opposed to database metadata).\n2. For the \u0027p4 archive\u0027 command, a special depot in which to copy the server data (versioned files and metadata).',l:''},'atomic change transaction':{d:'Grouping operations affecting a number of files in a single transaction. If all operations in the transaction succeed, all the files are updated. If any operation in the transaction fails, none of the files are updated.',l:''},'avatar':{d:'A visual representation of a Swarm user or group. Avatars are used in Swarm to show involvement in or ownership of projects, groups, changelists, reviews, comments, etc. See also the \"Gravatar\" entry in this glossary.',l:''},'base':{d:'For files: The file revision that contains the most common edits or changes among the file revisions in the source file and target file paths.  \n\nFor checked out streams: The public have version from which the checked out version is derived.',l:''},'binary file type':{d:'A Helix server file type assigned to a non-text file. By default, the contents of each revision are stored in full, and file revision is stored in compressed format.',l:''},'branch':{d:'(noun) A set of related files that exist at a specific location in the Helix Core depot as a result of being copied to that location, as opposed to being added to that location. A group of related files is often referred to as a codeline. \n(verb) To create a codeline by copying another codeline with the \u0027p4 integrate\u0027, \u0027p4 copy\u0027, or \u0027p4 populate\u0027 command.',l:''},'branch form':{d:'The form that appears when you use the \u0027p4 branch\u0027 command to create or modify a branch specification.',l:''},'branch mapping':{d:'Specifies how a branch is to be created or integrated by defining the location, the files, and the exclusions of the original codeline and the target codeline. The branch mapping is used by the integration process to create and update branches.',l:''},'branch view':{d:'A specification of the branching relationship between two codelines in the depot. Each branch view has a unique name and defines how files are mapped from the originating codeline to the target codeline. This is the same as branch mapping.',l:''},'broker':{d:'Helix Broker, a server process that intercepts commands to the Helix server and is able to run scripts on the commands before sending them to the Helix server.',l:''},'change review':{d:'The process of sending email to users who have registered their interest in changelists that include specified files in the depot.',l:''},'changelist':{d:'A list of files, their version numbers, the changes made to the files, and a description of the changes made. A changelist is the basic unit of versioned work in Helix server. The changes specified in the changelist are not stored in the depot until the changelist is submitted to the depot. See also atomic change transaction and changelist number.',l:''},'changelist form':{d:'The form that appears when you modify a changelist using the \u0027p4 change\u0027 command.',l:''},'changelist number':{d:'An integer that identifies a changelist. Submitted changelist numbers are ordinal (increasing), but not necessarily consecutive. For example, 103, 105, 108, 109. A pending changelist number might be assigned a different value upon submission.',l:''},'check in':{d:'To submit a file to the Helix server depot.',l:''},'check out':{d:'To designate one or more files, or a stream, for edit.',l:''},'checkpoint':{d:'A backup copy of the underlying metadata at a particular moment in time. A checkpoint can recreate db.user, db.protect, and other db.* files. See also metadata.',l:''},'classic depot':{d:'A repository of Helix Core files that is not streams-based. Uses the Perforce file revision model, not the graph model. The default depot name is depot. See also default depot, stream depot, and graph depot.',l:''},'client form':{d:'The form you use to define a client workspace, such as with the \u0027p4 client\u0027 or \u0027p4 workspace\u0027 commands.',l:''},'client name':{d:'A name that uniquely identifies the current client workspace. Client workspaces, labels, and branch specifications cannot share the same name.',l:''},'client root':{d:'The topmost (root) directory of a client workspace. If two or more client workspaces are located on one machine, they should not share a client root directory.',l:''},'client side':{d:'The right-hand side of a mapping within a client view, specifying where the corresponding depot files are located in the client workspace.',l:''},'client workspace':{d:'Directories on your machine where you work on file revisions that are managed by Helix server. By default, this name is set to the name of the machine on which your client workspace is located, but it can be overridden. Client workspaces, labels, and branch specifications cannot share the same name.',l:''},'code review':{d:'A process in Helix Swarm by which other developers can see your code, provide feedback, and approve or reject your changes.',l:''},'codeline':{d:'A set of files that evolve collectively. One codeline can be branched from another, allowing each set of files to evolve separately.',l:''},'comment':{d:'Feedback provided in Helix Swarm on a changelist, review, job, or a file within a changelist or review.',l:''},'commit server':{d:'A server that is part of an edge/commit system that processes submitted files (checkins), global workspaces, and promoted shelves.',l:''},'conflict':{d:'1. A situation where two users open the same file for edit. One user submits the file, after which the other user cannot submit unless the file is resolved. \n2. A resolve where the same line is changed when merging one file into another. This type of conflict occurs when the comparison of two files to a base yields different results, indicating that the files have been changed in different ways. In this case, the merge cannot be done automatically and must be resolved manually. See file conflict.',l:''},'copy up':{d:'A Helix server best practice to copy (and not merge) changes from less stable lines to more stable lines. See also merge.',l:''},'counter':{d:'A numeric variable used to track variables such as changelists, checkpoints, and reviews.',l:''},'default changelist':{d:'The changelist used by a file add, edit, or delete, unless a numbered changelist is specified. A default pending changelist is created automatically when a file is opened for edit.',l:''},'deleted file':{d:'In Helix server, a file with its head revision marked as deleted. Older revisions of the file are still available. See also \"obliterate\".',l:''},'delta':{d:'The differences between two files.',l:''},'depot':{d:'A file repository hosted on the server. A depot is the top-level unit of storage for versioned files, which are also known as depot files, archive files, or source files. It contains all versions of all files ever submitted to the depot, including deleted files (but not obliterated files). There can be multiple depots on a single installation.',l:''},'depot root':{d:'The topmost (root) directory for a depot.',l:''},'depot side':{d:'The left side of any client view mapping, specifying the location of files in a depot.',l:''},'depot syntax':{d:'Helix server syntax for specifying the location of files in the depot. Depot syntax begins with: //depot/',l:''},'diff':{d:'(noun) A set of lines that do not match when two files, or stream versions, are compared. A conflict is a pair of unequal diffs between each of two files and a base, or between two versions of a stream.\n(verb) To compare the contents of files or file revisions, or of stream versions.\nSee also conflict.',l:''},'edge server':{d:'A replica server that is part of an edge/commit system that is able to process most read/write commands, including \u0027p4 integrate\u0027, and also deliver versioned files (depot files).',l:''},'exclusionary access':{d:'A permission that denies access to the specified files.',l:''},'exclusionary mapping':{d:'A view mapping that excludes specific files or directories.',l:''},'extension':{d:'Custom logic that runs in a Lua engine embedded into the Helix server. Helix Core Extensions are a recent alternative to triggers, which require a runtime that is external to the server. See the Helix Core Extensions Developer Guide.',l:''},'file conflict':{d:'In a three-way file merge, a situation in which two revisions of a file differ from each other and from their base file.\nAlso, an attempt to submit a file that is not an edit of the head revision of the file in the depot, which typically occurs when another user opens the file for edit after you have opened the file for edit.',l:''},'file pattern':{d:'Helix server command line syntax that enables you to specify files using wildcards.',l:''},'file repository':{d:'The server\u0027s copy of all files, which is shared by all users. In Helix server, this is called the depot.',l:''},'file revision':{d:'A specific version of a file within the depot. Each revision is assigned a number, in sequence. Any revision can be accessed in the depot by its revision number, preceded by a pound sign (#), for example testfile#3.',l:''},'file tree':{d:'All the subdirectories and files under a given root directory.',l:''},'file type':{d:'An attribute that determines how Helix server stores and diffs a particular file. Examples of file types are text and binary.',l:''},'fix':{d:'A job that has been closed in a changelist.',l:''},'form':{d:'A screen displayed by certain Helix server commands. For example, you use the change form to enter comments about a particular changelist to verify the affected files.',l:''},'forwarding replica':{d:'A replica server that can process read-only commands and deliver versioned files (depot files). One or more replicate servers can significantly improve performance by offloading some of the master server load. In many cases, a forwarding replica can become a disaster recovery server.',l:''},'Git Connector':{d:'The Git Connector component of Helix4Git interacts with your native Git clients. It serves Git commands and performs all Git repo operations. See \"hybrid workspace\".',l:''},'Git Fusion':{d:'(No longer offered to new customers) A Perforce product that integrates Git with Helix, offering enterprise-ready Git repository management, and workflows that allow Git and Helix server users to collaborate on the same projects using their preferred tools.',l:''},'graph depot':{d:'A depot of type graph that is used to store Git repos in the Helix server. See also Helix4Git and classic depot.',l:''},'group':{d:'A feature in Helix server that makes it easier to manage permissions for multiple users.',l:''},'have list':{d:'The list of file revisions currently in the client workspace.',l:''},'head revision':{d:'The most recent revision of a file within the depot. Because file revisions are numbered sequentially, this revision is the highest-numbered revision of that file.',l:''},'heartbeat':{d:'A process that allows one server to monitor another server, such as a standby server monitoring the master server (see the p4 heartbeat command).',l:''},'Helix server':{d:'The Helix server depot and metadata; also, the program that manages the depot and metadata, also called Helix Core server.',l:''},'Helix TeamHub':{d:'A Perforce management platform for code and artifact repository. TeamHub offers built-in support for Git, SVN, Mercurial, Maven, and more.',l:''},'hybrid workspace':{d:'A workspace that supports both repos of type graph (see \"Git Connector\"), and the Helix Core file revision model.',l:''},'integrate':{d:'To compare two sets of files (for example, two codeline branches) and determine which changes in one set apply to the other, determine if the changes have already been propagated, and propagate any outstanding changes from one set to another.',l:''},'job':{d:'A user-defined unit of work tracked by Helix server. The job template determines what information is tracked. The template can be modified by the Helix server system administrator. A job describes work to be done, such as a bug fix. Associating a job with a changelist records which changes fixed the bug.',l:''},'job daemon':{d:'A program that checks the Helix server machine daily to determine if any jobs are open. If so, the daemon sends an email message to interested users, informing them the number of jobs in each category, the severity of each job, and more.',l:''},'job specification':{d:'A form describing the fields and possible values for each job stored in the Helix server machine.',l:''},'job view':{d:'A syntax used for searching Helix server jobs.',l:''},'journal':{d:'A file containing a record of every change made to the Helix server’s metadata since the time of the last checkpoint. This file grows as each Helix server transaction is logged. The file should be automatically truncated and renamed into a numbered journal when a checkpoint is taken.',l:''},'journal rotation':{d:'The process of renaming the current journal to a numbered journal file.',l:''},'journaling':{d:'The process of recording changes made to the Helix server’s metadata.',l:''},'label':{d:'A named list of user-specified file revisions.',l:''},'label view':{d:'The view that specifies which filenames in the depot can be stored in a particular label.',l:''},'lazy copy':{d:'A method used by Helix server to make internal copies of files without duplicating file content in the depot. A lazy copy points to the original versioned file (depot file). Lazy copies minimize the consumption of disk space by storing references to the original file instead of copies of the file.',l:''},'librarian':{d:'The librarian subsystem of the server stores, manages, and provides the archive files to other subsystems of the Helix Core server.',l:''},'license file':{d:'A file that ensures that the number of Helix server users on your site does not exceed the number for which you have paid.',l:''},'list access':{d:'A protection level that enables you to run reporting commands but prevents access to the contents of files.',l:''},'local depot':{d:'Any depot located on the currently specified Helix server.',l:''},'local syntax':{d:'The syntax for specifying a filename that is specific to an operating system.',l:''},'lock':{d:'1. A file lock that prevents other clients from submitting the locked file. Files are unlocked with the \u0027p4 unlock\u0027 command or by submitting the changelist that contains the locked file. 2. A database lock that prevents another process from modifying the database db.* file.',l:''},'log':{d:'Error output from the Helix server. To\nspecify a log file, set the P4LOG environment variable or use the p4d -L flag when starting the service.',l:''},'mapping':{d:'A single line in a view, consisting of a left side and a right side that specify the correspondences between files in the depot and files in a client, label, or branch. See also workspace view, branch view, and label view.',l:''},'master server':{d:'The innermost Helix Core server in a multi-server topology. In the server spec, the Services field must be set to \u0027commit-server\u0027 for edge-commit architecture, and is typically set to \u0027standard\u0027 for master-replica architecture.',l:''},'MDS checksum':{d:'The method used by Helix server to verify the integrity of versioned files (depot files).',l:''},'merge':{d:'1. To create new files from existing files, preserving their ancestry (branching). 2. To propagate changes from one set of files to another. 3. The process of combining the contents of two conflicting file revisions into a single file, typically using a merge tool like P4Merge.',l:''},'merge file':{d:'A file generated by the Helix server from two conflicting file revisions.',l:''},'metadata':{d:'The data stored by the Helix server that describes the files in the depot, the current state of client workspaces, protections, users, labels, and branches. Metadata is stored in the Perforce database and is separate from the archive files that users submit.',l:''},'modification time or modtime':{d:'The time a file was last changed.',l:''},'MPM':{d:'Multi-Processing Module, a component of the Apache web server that is responsible for binding to network ports, accepting requests, and dispatch operations to handle the request.',l:''},'nonexistent revision':{d:'A completely empty revision of any file. Syncing to a nonexistent revision of a file removes it from your workspace. An empty file revision created by deleting a file and the #none revision specifier are examples of nonexistent\nfile revisions.',l:''},'numbered changelist':{d:'A pending changelist to which Helix server has assigned a number.',l:''},'obliterate':{d:'The p4 obliteratre command permanently removes files and their history from the depot. See also \"deleted file\".',l:''},'offline database':{d:'A copy of a Helix Core Server database that is kept up to date by manual or scripted processes that replay journals from a Helix Core Server.',l:''},'opened file':{d:'A file you have checked out in your client workspace as a result of a Helix Core server operation (such as an edit, add, delete, integrate). Opening a file from your operating system file browser is not tracked by Helix Core server.',l:''},'owner':{d:'The Helix server user who created a particular client, branch, or label.',l:''},'p4':{d:'1. The Helix Core server command line program. 2. The command you issue to execute commands from the operating system command line.',l:''},'p4d':{d:'The program that runs the Helix server; p4d manages depot files and metadata.',l:''},'P4PHP':{d:'The PHP interface to the Helix API, which enables you to write PHP code that interacts with a Helix server machine.',l:''},'PECL':{d:'PHP Extension Community Library, a library of extensions that can be added to PHP to improve and extend its functionality.',l:''},'pending changelist':{d:'A changelist that has not been submitted.',l:''},'project':{d:'In Helix Swarm, a group of Helix server users who are working together on a specific codebase, defined by one or more branches of code, along with options for a job filter, automated test integration, and automated deployment.',l:''},'protections':{d:'The permissions stored in the Helix server’s protections table.',l:''},'proxy server':{d:'A Helix server that stores versioned files. A proxy server does not perform any commands. It serves versioned files to Helix server clients.',l:''},'RCS format':{d:'Revision Control System format. Used for storing revisions of text files in versioned files (depot files). RCS format uses reverse delta encoding for file storage. Helix server uses RCS format to store text files. See also reverse delta storage.',l:''},'read access':{d:'A protection level that enables you to read the contents of  files managed by Helix server but not make any changes.',l:''},'remote depot':{d:'A depot located on another Helix server accessed by the current Helix server.',l:''},'replica':{d:'A Helix Core Server that automatically maintains a full or partial copy of metadata and none, some, or all of the related file archives by copying data from a master  Helix Core Server using \u0027p4 pull\u0027 or \u0027p4 journalcopy\u0027.',l:''},'repo':{d:'A graph depot contains one or more repos, and each repo contains files from Git users.',l:''},'reresolve':{d:'The process of resolving a file after the file is resolved and before it is submitted.',l:''},'resolve':{d:'The process you use to manage the differences between two revisions of a file, or two versions of a stream. You can choose to resolve file conflicts by\nselecting the source or target file to be submitted, by merging the contents of conflicting files, or by making additional changes. To resolve stream conflicts, you can choose to accept the public source, accept the checked out target, manually accept changes, or combine path fields of the public and checked out versions while accepting all other changes made in the checked out version.',l:''},'reverse delta storage':{d:'The method that Helix server uses to store revisions of text files. Helix server stores the changes between each revision and its previous revision, plus the full text of the head revision.',l:''},'revert':{d:'To discard the changes you have made to a file in the client workspace before a submit.',l:''},'review access':{d:'A special protections level that includes read and list accesses and grants permission to run the p4 review command.',l:''},'review daemon':{d:'A program that periodically checks the Helix server machine to determine if any changelists have been submitted. If so, the daemon sends an email message to users who have subscribed to any of the files included in those changelists, informing them of changes in files they are interested in.',l:''},'revision number':{d:'A number indicating which revision of the file is being referred to, typically designated with a pound sign (#).',l:''},'revision range':{d:'A range of revision numbers for a specified file, specified as\nthe low and high end of the range. For example, myfile#5,7 specifies revisions 5 through 7 of myfile.',l:''},'revision specification':{d:'A suffix to a filename that specifies a particular revision of that file. Revision specifiers can be revision numbers, a revision range, change numbers, label names, date/time specifications, or client names.',l:''},'RPM':{d:'RPM Package Manager. A tool, and package format, for managing the installation, updates, and removal of software packages for Linux distributions such as Red Hat Enterprise Linux, the Fedora Project, and the CentOS Project.',l:''},'server data':{d:'The combination of server metadata (the Helix server database) and the depot files (your organization\u0027s versioned source code and binary assets).',l:''},'server root':{d:'The topmost directory in which p4d stores its metadata (db.* files) and all versioned files (depot files or source files). To specify the server root, set the P4ROOT environment variable or use the p4d -r flag.',l:''},'service':{d:'In the Helix Core server, the shared versioning service that responds to requests from Helix server client applications. The Helix server (p4d) maintains depot files and metadata describing the files and also tracks the state of client workspaces.',l:''},'shelve':{d:'The process of temporarily storing files in the Helix server without checking in a changelist.',l:''},'status':{d:'For a changelist, a value that indicates whether the changelist\nis new, pending, or submitted. For a job, a value that indicates whether the job is open, closed, or suspended. You can customize job statuses. For the \u0027p4 status\u0027 command, by default the files opened and the files that need to be reconciled.',l:''},'storage record':{d:'An entry within the db.storage table to track references to an archive file.',l:''},'stream':{d:'A \"branch\" with built-in rules that determines what changes should be propagated and in what order they should be propagated.',l:''},'stream depot':{d:'A depot used with streams and stream clients. Has structured branching, unlike the free-form branching of a \"classic\" depot. Uses the Perforce file revision model, not the graph model. See also classic depot and graph depot.',l:''},'stream hierarchy':{d:'The set of parent-to-child relationships between streams in a stream depot.',l:''},'stream view':{d:'A stream view is defined by the Paths, Remapped, and Ignored fields of the stream specification. (See Form Fields in the p4 stream command)',l:''},'submit':{d:'To send a pending changelist into the Helix server depot for processing.',l:''},'super access':{d:'An access level that gives the user permission to run every Helix server command, including commands that set protections, install triggers, or shut down the service for maintenance.',l:''},'symlink file type':{d:'A Helix server file type assigned to symbolic links. On platforms that do not support symbolic links, symlink files appear as small text files.',l:''},'sync':{d:'To copy a file revision (or set of file revisions) from the Helix server depot to a client workspace.',l:''},'target file':{d:'The file that receives the changes from the donor file when you integrate changes between two codelines.',l:''},'text file type':{d:'Helix server file type assigned to a file that contains only ASCII text, including Unicode text. See also binary file type.',l:''},'theirs':{d:'The revision in the depot with which the client file (your file) is merged when you resolve a file conflict. When you are working with branched files, theirs is the donor file.',l:''},'three-way merge':{d:'The process of combining three file revisions. During a three-way merge, you can identify where conflicting changes have occurred and specify how you want to resolve the conflicts.',l:''},'topology':{d:'The set of Helix Core services deployed in a multi-server installation, which might include commit-server, edge servers, standby servers, proxies, brokers, and more.',l:''},'trigger':{d:'A script that is automatically invoked by Helix server when various conditions are met. (See \"Helix Core Server Administrator Guide\" on \"Triggers\".)',l:''},'two-way merge':{d:'The process of combining two file revisions. In a two-way merge, you can see differences between the files.',l:''},'typemap':{d:'A table in Helix server in which you assign file types to files.',l:''},'user':{d:'The identifier that Helix server uses to determine who is performing an operation. The three types of users are standard, service, and operator.',l:''},'versioned file':{d:'Source files stored in the Helix server depot, including one or more revisions. Also known as an archive file. Versioned files typically use the naming convention \u0027filenamev\u0027 or \u00271.changelist.gz\u0027.',l:''},'view':{d:'A description of the relationship between two sets of files. See workspace view, label view, branch view.',l:''},'wildcard':{d:'A special character used to match other characters in strings. The following wildcards are available in Helix server: * matches anything except a slash; ... matches anything including slashes; %%0 through %%9 is used for parameter substitution in views.',l:''},'workspace':{d:'See client workspace.',l:''},'workspace view':{d:'A set of mappings that specifies the correspondence between file locations in the depot and the client workspace.',l:''},'write access':{d:'A protection level that enables you to run commands that alter the contents of files in the depot. Write access  includes read and list accesses.',l:''},'XSS':{d:'Cross-Site Scripting, a form of web-based attack that injects malicious code into a user\u0027s web browser.',l:''},'yours':{d:'The edited version of a file in your client workspace when you resolve a file. Also, the target file when you integrate a branched file.',l:''}});