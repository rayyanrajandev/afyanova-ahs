# The Plan, Explained Simply

A short version of `authorization-and-next-workspaces-plan.md`, with no
technical words. Read this one first.

---

## Think of the app as a big hospital building

Every job someone does happens **behind a door**.

- One door is "take the patient's blood."
- One door is "write down what the doctor said."
- One door is "give the patient medicine."

Every worker carries a **badge with keys on it**. A nurse's badge has nurse
keys. A lab worker's badge has lab keys.

If your badge has the right key, the door opens. If not, the door stays shut.

---

## What we found

We checked **all 257 doors** in the building.

> **95 doors have no key. Not a broken key — no key at all, on anybody's badge.**

Nobody in the whole hospital can open them. Not the nurse, not the doctor, not
the boss.

---

## We already hit one of these doors

The lab room had this problem.

A lab worker could walk in and **see** the list of tests to do. But when they
tried to actually start a test, the door would not open. The lab worker could
look at their work but could not do it.

We found it and fixed it. **The lab works now.**

---

## There may be one more, and it matters

There is a door doctors use every single day: **"save my notes again."**

Writing notes the *first* time works fine. But saving *changes* to those notes
goes through a different door — and that door looks like it has no key.

We are not 100% sure yet, because the boss's badge opens every door, so if you
tested it as the boss it would have seemed fine.

**So the very first job is: log in as a normal doctor and try it.** If notes
really cannot be saved twice, we fix that today, before anything else.

---

## Why this keeps happening

There are **two different lists** that say who gets which keys.

One person adds a door and checks List A. Another person hands out keys using
List B. The two lists never talk to each other.

So doors keep getting built that no key opens — and nobody notices, because
**nothing complains**. There is no alarm. The worker just stands there, the door
does not open, and they do not know why.

That is the real problem. Not the 95 doors. The *silence*.

---

## The fix

**1. Build an alarm.**
A little robot that walks the whole building every day and tries every door. If
it finds a door no key opens, it shouts. This is the most important part — it
stops the problem coming back forever.

**2. Then go door by door.**
For each of the 95, pick one of two answers:

- *Somebody should be able to open this* → give them the key.
- *Nobody should, and nothing uses it* → take the door out of the wall.

"Leave it locked forever" is not allowed. That is what we have now, and it looks
exactly like a bug.

**3. Then build the two new rooms.**
The **X-ray room** and the **pharmacy**. We build them *after* the doors are
fixed, so we do not repeat what happened with the lab.

The X-ray room should be quick. Most of the machinery from the lab room already
works for it.

---

## One more thing: nobody is checking the money

Right now a doctor can send a patient to the lab, and the lab will do the test —
**even if the patient has not paid.**

In most private hospitals in Tanzania, a patient who pays cash must pay first.
Nothing in the app checks this. There is not even a way to say "this patient is
waiting to pay."

We are adding that waiting step **now**, while it is easy. The cashier's own
screen comes later, but the step it needs will already be there waiting for it.

---

## In one sentence

**Some doors in our hospital have no keys, nothing tells us when that happens,
so first we build the alarm, then we fix the doors, then we build the new
rooms — and we make sure someone checks the money.**
