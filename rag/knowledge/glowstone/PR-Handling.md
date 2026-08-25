This page describes the process used by Glowstone PR handlers and contributors to manage pull requests. For guidelines on creating your own pull requests, see [[Contributing]] and [[Code Style]].

**How the process is meant to go:**

1. A PR is opened according to the guidelines in [[Contributing]].
1. The [Contributor License Agreement](https://glowstone.net/cla) is signed if it hasn't been already.
1. The PR is assigned to one of the PR handlers (self-assigned or by a higher up).
1. The assigned handler and other community members provide feedback on the PR.
1. More commits are pushed to the PR as work is done.
1. Eventually, the submitter and the handler/community seem to agree the PR is complete.
1. A maintainer gives their approval and squahes and then merges the PR.

**How "work in progress" PRs get reviewed:**

1. Author opens a PR with the `Status: in progress` label to indicate it is a "work in progress".
2. Author updates PR as required, gathering community feedback.
3. When the author feels the PR is ready for full review, they replace the `Status: in progress` label with `Status: ready`
4. Normal PR review continues (see above).

**Notes:**
* Handler will be in communication with maintainers during PR process.
* PRs which implement some discrete chunk of a feature (e.g. Cows) but not the whole feature (e.g. all Animals) are acceptable to a degree.
* PRs which are intended to improve performance should only be accepted if there is evidence they succeed at doing so.
* PRs which fix bugs are acceptable.
* PRs which are inactive for a period of a week, or fail to meet the contributing guidelines within 3 days of their creation, are closed. The submitter may reopen them later.
* If a PR is determined to be making a change which is not desirable, and the submitter does not wish to change direction, it is closed.
    * WIP PRs that are inactive for longer than 2 months will be closed.
    * Handlers will do their best to remind authors about updating PRs.
    * If an author plans to go missing, or cannot update their PR right away, they should mention that. Suitable comments include "I'll update this in a few days" or "When I get back from vacation". Non-suitable comments are "Will do, thanks!" or "When I have time". 
    * If an author has provided an update as to when they'll be providing some kind of update to their PR, the reviewer may consider starting the timer for closure after the mentioned time (ie: 1 week after they said "I'm busy this week, but should have time next week").
* If multiple people have contributed code to the PR, those other than the commit author should be @ credited in the commit message.
* Assignment of a PR to a handler can change after it is first assigned.
* PRs can re-enter WIP status after being opened.
