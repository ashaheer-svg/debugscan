you are a senior Linux administrator, senior software developer, and a UI expert designer

I am looking to create a software for analyzing a Synology Debug.dat file.
Synology Debug.dat files are a compilation of multiple log files and configuration files which upon studying can identify potential and current issues the nas is undergoing. 
Synology NAS are currently running on cutdown version of Debian Linux. 

the this contains the full log structure the files can be very large and may be inefficient to process such a large amount of data, how ever if we can identify the relevant files required for testing then those subset of files can be checked.


the features i need checked are :

1. hardware issues.
2. networking issues
3. raid issues.
4. functionality and security issues
5. any other issues not addressed above
6. overall view.

each element has to be checked against stability and performance.

I already have a Hardwarev2.md file which extracts key model, serial number, drives, raid volume details from the log file. this facility is provided free to tenants on the subscription however for deep Analysis ( with AI ) will incur a cost in Scan Tokens.

analyzing will be done in 2 levels.  Each analysis will have a cost in terms of scan tokens.
all analysis will be done by AI.


level 1. - basic analysis as mentioned above. should provide issues with proof from logs, risk factor, how to fix.
	if a problem exists attention has to be taken to identify the root issue.
	example: a potential drive failure may show up as : file system issues, delayed response from RAID subsystem, Drive failure. it is important to identify the root cause.
	may use a smaller input/output context window with a Weaker AI model.
	usage = 1 scan token
	critical logs entries ( last 500 lines, max 1 year  ) from essential logs will be retained.

level 2. - this is for multiple debug file checking with deeper, stronger diagnosis. may use larger input/output context window with a stronger AI. usage = 2 scan tokens. the source will be from the critical log entries collected, by a level 1 scan. this will show the progress of a problem, whether it has been fixed.

any problems identified has to be proven with log entries.

example Synology NAS devices typically run with low ram, if there is proof this is hurting performance, with higher io waits then this has to be notified ( with proof ), but just because ram is low is not a major reason.

scan tokens will be available for purchase online.
access to the portal will be on subscription basis, this will be developed later.

AI model will be obtained from groq api key.  

this has to be a multi tenant system, with the following roles.

a1. Admin user category - has access to the main administration page and can:
a1.1	create/edit/delete/activate/deactivate tenant users.
a1.2	select ai models to choose from groq's entire offering.
a1.3	each scan level will have the option to select AI model
a1.4	each scan level will have the option to select the input/output token usage limits.
a1.5	each tenant created will use the above values
a1.6	each tenant created will have usage summery including tokens available, tokens used, scans done at each level shown 
a1.7	all tenants will be shown in list view.
a1.8	system health summery should be shown
a1.9	overall token utilization should be shown
a1.10	all debug.dat files uploaded and reports generated will have a global retention period. this will be editable. 
a1.11	an error log has to be maintained when scanning log files, if data extraction causes errors or data is not found , the debug file in question will be flagged and marked for analysis. and be available for download. 


a2. tenant user category - has access to own dashboard and  
a2.1	will have the ability to create/edit/delete projects.
a2.2	each project will have 1 NAS, identified by serial number of the device. the model and serial number will be extracted from the debug.dat file.
a2.2.1	when a serial number is identified, and the serial number already exists with previous data, the system will ask the user when to create a new project or to add to the existing project ( with the same serial number )
a2.3	when a serial number is not identified  the system will ask the user when to create a new project or to add to the existing project ( selectable ) in the case of a temporary replacement for warranty 
a2.4	all projects will be displayed in list view with project name, device model, serial number, drive bays.  selecting a more info items will bring down NAS specs, recovered from the initial scan. a button will also be available for level 1 scanning ( AI Based ) or Level 2 Scanning (Deeper AI BAsed )
a2.5	each project may upload multiple debug.dat files to check resolution of issue, each such scan across multiple debug.dat files will be a level 2 scan.


b1. this has to be a password protected system with user rights management
b2. web based software. using php for the front end and for backend with progresql for the data base.
b3. system has to use design.md file available in the folder for UI design.
b4. all code should be properly commented and use proper coding methods.
b5. as the application is being developed, key data should be stored in a keydata.md file, and this keydata.md file should be accessed before any process starts.

keydata.md file.
this file should be accessed along with activedesign.md file every time a prompt is run.
keydata.md file will be written with essential data regarding the project to prevent antigravity from re-reading code every time.
this file will be read write allowed. all files should have versioning enabled by default, and the keydata.md file will maintain the changelog.

activedesign.md file
this file should be accessed along with keydata.md file every time a prompt is run.
this file contains all the design guidelines for the UI/UX
this file will be read only.

hardwarev2.md
this file contains the file entry locations for identifying hardware and software configurations whilst having identification for DSM version numbers

end result
end result expected is a complete and bug free multitenant system,

environment
i will provide a Debian 12 VPS with 4vcpu and 6gb ram. you will have to install the full working environment including full stack.
access will be via SSH. 

tools used to upload will have to be proper versions as the development environment will be windows based and deployment will be Linux based.

to handle file uploads, downloads, and local command running, a tool can be placed on the VPS, to prevent file corruption in transit.

development folder structure on local machine;

local 
--devtmp  ( temporary files to be stored here ) 
--guide ( important instruction files )   
--upload  ( entire file upload directory )
(files and folders uploaded here)

remote structure;

webroot 
--devtmp  ( temporary files to be stored here ) 
--guide ( important instruction files )   
(files and folders uploaded here)


a folder named "tmpdev"  should be created on the local folder and remote folder to store temporary files, this will be deleted once deployment is complete. 

the folder structure should be placed in a webroot ( www ). making it compatible with cPanel based webhosting in the future.  

Vps server details
server ip: 142.91.101.142
username: root
password: z68YQFuru8QjZ4Mv

let me know the following 

c1. understand the document first. 
c2. ask me any questions you need to clarify 
c3. PLAN before deployment

