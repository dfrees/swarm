                             Release Notes for
                               Helix Swarm

                              Version ~:VERSION:~

Introduction

    Helix Swarm (hereafter referred to as "Swarm") enables collaboration
    and code review for teams using Helix VCS that will help your teams ship
    quality software faster.

    This document lists all user-visible changes to Helix Swarm for
    Release ~:VERSION:~.

    Perforce numbers releases YYYY.R/CCCCC, e.g. 20~:VER:~/123456. YYYY is the
    year; R is the release of that year; CCCCC is the bug fix change level.
    Each bug fix in these release notes is marked by its change number.
    Any release includes (1) all bug fixes of all previous releases and (2)
    all bug fixes of the current release up to the bug fix change level.

    The most up to date version of these release notes can be found here:

    * http://www.perforce.com/perforce/doc.current/user/swarm_relnotes.txt

    Please send all feedback to support@perforce.com.

---------------------------------------------------------------------------

Documentation

    Swarm Release ~:VERSION:~ documentation is included in the distribution
    under the "public/docs" folder. The documentation can be accessed from
    within Swarm from the "Help" icon in the toolbar when you are logged in.

    Additionally, the documentation is available online:

    * http://www.perforce.com/manuals/v~:VER:~/swarm

Supported Client Browsers

    Swarm supports the following client web browsers at the latest stable
    browser version:

    * Apple Safari
    * Google Chrome
    * Microsoft Edge
    * Mozilla Firefox

    We recommend the use of the latest stable version of the browsers
    listed above for the best experience when using Swarm.

    Other web browsers might also work, including prior, development or
    beta builds of the above web browsers, but are not officially
    supported.

    Swarm requires that JavaScript and cookies are enabled in the web
    browser.

Installation and Supported Platforms

    Please see the installation section of the documentation:
    https://www.perforce.com/manuals/v~:VER:~/swarm/Content/Swarm/chapter.setup.html
    
    Packages are available for Ubuntu 18.04/20.04, CentOS 7 and Amazon Linux 2.

    For RHEL based distributions, we recommend the latest supported versions of
    those platforms (so 7.9 or 8.5).
    
    Support for Ubuntu 16.04 has been dropped from release 21.1 onwards.
    
Upgrading from earlier versions

    Please see the upgrade section of the documentation:
    https://www.perforce.com/manuals/v~:VER:~/swarm/Content/Swarm/setup.upgrade.html

License

    Please see the separate "license" file, a peer to this file, or:
    https://www.perforce.com/perforce/r~:VER:~/user/swarm_license.txt

Known Limitations

    Review Page
    
         Some review page features are not yet supported by the new review
         page preview. This will be addressed in a later release of Swarm.
         In the meantime, if you need to use any of the unsupported features,
         switch back to the original Swarm review page with the Preview
         toggle. 

    Task Stream Reviews

        Pre-commit reviews in a task stream are not yet supported.

    Swarm OVA installation fails with a Run p4 login2 error

        Issue: Swarm OVA deployment against an MFA enabled Helix Server
        fails with a Run p4 login2 error.

        Workaround: You must run p4 login2 for a super user account that
        has MFA enabled before deploying the Swarm OVA.

--------------------------------------------------------------------------
Upgrading from Swarm 2019.1 and earlier

    Swarm 2019.2 introduced a Redis in-memory cache to improve performance and 
    reduce the load on the Helix Core server. This replaces the file-based cache 
    that was previously used by Swarm.

    On Swarm systems with a large number of users, groups, and projects, the 
    initial population of this cache can take some time. If you have a large 
    Swarm system you should read through the Redis section of the documentation:

    https://www.perforce.com/manuals/v22.1/swarm/Content/Swarm/admin.redis.html

--------------------------------------------------------------------------
Upgrade process for 2017.3

    If you are upgrading from Swarm 2017.2 or earlier you should run the 
    index upgrade, this ensures that the review activity history is displayed
    in the correct order on the Dashboard, and Reviews list pages.

    This step is only required the first time you upgrade your Swarm system
    to 2017.3 or later. Subsequent Swarm upgrades do not require the index
    to be upgraded. If this is a new Swarm installation, the index does not
    need to be upgraded.

    To upgrade the index, log in as an admin or super user and visit:
    http://SWARM_HOST/upgrade

    Please see the upgrade section of the documentation:
    https://www.perforce.com/manuals/v~:VER:~/swarm/Content/Swarm/setup.upgrade.html

--------------------------------------------------------------------------
Important Notices

    We are deprecating APIs older than v9, with a plan to remove them
    from Swarm in the 2022.2 release. We recommend that anything that
    uses the Swarm APIs is updated to use at least v9 by then.
    
    v9 is the latest complete set of APIs. The v11 APIs are not yet complete,
    and might undergo refinement in the upcoming releases.
    
    We have removed support for versions of PHP older than 7.2 in Swarm 2022.1.
    
    We have dropped support for CentOS 8.
    
    This is part of our commitment to move away from using versions of
    platforms that have reached End-of-Life (EOL).

    In Swarm 2020.2, we have changed the default batch size for results 
    returned on the reviews list page, from 50 to 15. This should mean
    results are returned to the user faster. However, if users have large
    screens then multiple requests will be made to the server to populate 
    the entire page, which might impact overall P4D server performance.
    See the filter-max configurable in the documentation if you need to
    configure this.
    
    The Zend Framework was forked to the open source Laminas project, and
    Swarm was changed to use this in 2020.1. If you have existing custom 
    Swarm modules created for Swarm 2019.3 or earlier, you must update them 
    to use the Laminas framework. 

    This means that you will need to ensure that you can install PHP 7
    or greater before you install Swarm 2019.1 or greater.
    
