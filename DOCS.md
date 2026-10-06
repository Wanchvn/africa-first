Add these lines to the Development Log section:

Date	Change	Notes
2026-10-04	Database schema created	users, posts, follows tables
2026-10-04	register.php working	Password hashing verified
2026-10-04	login.php + logout.php working	Sessions functional
2026-10-04	profile.php working	User can post and view own posts
And check off Phase 1 items in the roadmap:

☑ User registration
☑ Login / logout
☑ User profile
☑ Create post


 
Add to the Development Log:

Date	Change	Notes
2026-10-04	follow.php, feed.php, discover.php	Follow system + feed live
2026-10-04	profile.php supports ?id=	Can view other users
Check off in the roadmap:

☑ Follow / unfollow
☑ Feed (posts from followed users)
Phase 1 MVP is complete. Everything below is growth.



Date	Change	Notes
2026-10-04	Photo uploads working	MIME validation, random filenames, display in feed + profile
Check off in the roadmap:

☑ Image uploads
Phase 1 MVP is now fully complete. You have auth, profiles, posts, follows, a feed, and photos.


Date	Change	Notes
2026-10-04	UI polished	style.css, shared header/footer, African-inspired palette
Add a new section under "Key Decisions":

Decision	Reason
Warm African palette (terracotta, deep brown, sand, Qarota green)	Local identity, not a Facebook clone
Shared header/footer via includes/	Consistency across pages, easy to update nav
Card-based layout




Date	Change	Notes
2026-10-04	Likes + comments working	likes/comments tables, interact.php handler, counts in feed and profile
Check off in the roadmap:

☑ Likes
☑ Comments
Phase 2 is now underway. Remaining: notifications, search, image uploads (done).



Add to the Development Log:

Date	Change	Notes
2026-10-04	Profile pictures working	Upload, display in profiles, feed, comments; placeholder for users without avatars
Check off in the roadmap:

☑ Profile pictures (add this line if it's not there)
And add to "Key Decisions":

Decision	Reason
Initial-letter placeholder avatars	Users feel present even before uploading a picture





Update DOCS.md Development Log:

Date	Change	Notes
2026-10-04	Notifications working	Likes, comments, follows trigger notifications; unread badge in header; deduping on toggle
Check off in the roadmap:

☑ Notifications
Add to Key Decisions:

Decision	Reason
Notifications dedupe on un-like	Prevents stale notifications when users toggle likes


Add to Development Log:

Date	Change	Notes
2026-10-04	Data export working	ZIP with all user data: profile, posts, comments, likes, follows, notifications, media
Check off in Phase 3 roadmap:

☑ Data export (JSON + ZIP)
Add to Key Decisions:

Decision	Reason
Full ZIP export including media	Directly answers Facebook's "you can't take it with you" model
README.txt inside export	Educates users about their own data — not just a dump


Add to Development Log:

Date	Change	Notes
2026-10-05	Account deletion working	Password re-verify + DELETE confirmation; files and DB rows wiped
Check off in Phase 3:

☑ Account deletion


Add to Development Log:

Date	Change	Notes
2026-10-05	Report form working	Users can report posts with reason + details
2026-10-05	Admin panel working	Admin reviews reports, dismisses or removes posts
Check off in Phase 3 roadmap:

☑ Report / takedown form


Add to Development Log:

Date	Change	Notes
2026-10-05	Privacy dashboard working	Shows all stored data, activity counts, rights under Act 843
Check off in Phase 3 roadmap:

☑ Privacy dashboard
Phase 3 status:

☑ Data export
☑ Account deletion
☑ Report / takedown form
☑ Admin moderation panel
☑ Privacy dashboard


Add to Development Log:

Date	Change	Notes
2026-10-05	Mobile hamburger nav complete	☰ toggle, overlay dim, tap-outside close


dd to Development Log:

Date	Change	Notes
2026-10-05	Public moderation log live	Every removal and dismissal published; excerpts suppressed for sensitive categories
Add to Key Decisions:

Decision	Reason
Public moderation log	No shadow justice. Every action is visible and traceable.
Excerpt suppression for sensitive categories	Prevents amplifying CSAM, terrorism, nudity in the log itself
Admin ID kept in log even after deletion (ON DELETE SET NULL)	The log must outlive the admin who made the decision