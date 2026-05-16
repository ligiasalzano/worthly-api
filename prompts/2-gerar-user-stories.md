Look at the file `@docs/project-description.md` and work out user stories for the project.

Ask me any clarifying questions using AskUserQuestion tool, to improve the result.

Save result in the file `@docs/user-stories.md`.

Below you will see an example Project Description and example User Stories from it. Please mimic similar structure and depth of details.

---

# Example Project Description

I'm building FitSphere, a SaaS platform for online fitness coaching.  
Think “Trainerize + Stripe + Zoom” for independent personal trainers.

The goal is to allow fitness coaches to manage clients, create workout plans,
track progress, and handle subscriptions — all in one platform.

I need an experienced full-stack developer to build the MVP from scratch.

WHAT I NEED BUILT:

This is a SaaS platform with 4 user types:

1. Visitors (unauthenticated users)
2. Clients (people training with a coach)
3. Coaches (fitness professionals)
4. Admin (platform owner)

CORE FEATURES:

✓ User Authentication & Role-Based Access
- Sign up / Login (email + Google OAuth)
- Role selection during registration (Client or Coach)
- Separate dashboards per role
- Email verification & password reset

✓ Coach Profiles (Public Pages)
- Public profile page for each coach
- Bio, specialties, certifications
- Monthly subscription price
- Before/after gallery
- “Start Training” CTA

✓ Subscription & Payment System
- Stripe integration
- Monthly recurring subscription
- Free trial option (7 days)
- Automatic commission split (platform takes 15%)
- Subscription cancellation

✓ Client Management
- Coaches can add/manage clients
- View active/inactive clients
- Track subscription status
- Client notes (private)

✓ Workout Program Builder
- Coaches create workout programs
- Exercises with sets, reps, rest time
- Attach videos (YouTube/Vimeo links)
- Assign programs to clients
- Version history (basic)

✓ Progress Tracking
- Clients log workouts (completed sets)
- Track weight, body measurements
- Upload progress photos
- Graph progress over time

✓ Messaging System
- Direct messaging between client and coach
- Real-time or near real-time updates
- Message read status

✓ Admin Dashboard
- View all users
- View subscriptions
- View total revenue
- Suspend accounts
- Configure platform commission

✓ Email Notifications
- Welcome emails
- Subscription confirmation
- Trial ending reminder
- Payment failure notification
- Weekly progress reminder

---

# Example User Stories

## Overview

This document contains user stories for FitSphere, a SaaS platform that connects fitness coaches with their clients for online training management.

**User Types:**
- **Visitor** - Unauthenticated user browsing coaches
- **Client** - User subscribed to a coach
- **Coach** - Fitness professional managing clients
- **Admin** - Platform administrator

---

## 1. Authentication & Registration

### US-1.1: Client Registration (Email)
**As a** Client  
**I want to** register using email and password  
**So that** I can subscribe to a coach and access my training programs

**Acceptance Criteria:**
- [ ] Registration form collects: name, email, password, password confirmation
- [ ] Email must be unique
- [ ] Password must meet minimum security requirements (8+ characters)
- [ ] User selects role = Client
- [ ] Email verification required
- [ ] User redirected to client dashboard after verification

**Expected Result:** Client account is created and ready for subscription.

---

### US-1.2: Coach Registration
**As a** Coach  
**I want to** register and create a professional profile  
**So that** I can offer online training services

**Acceptance Criteria:**
- [ ] Registration form collects: name, email, password
- [ ] Coach role selected
- [ ] Coach profile created in “draft” state
- [ ] Coach must complete profile before going public
- [ ] Admin notified of new coach registration

**Expected Result:** Coach account created and ready for profile completion.

---

### US-1.3: Google OAuth Login
**As a** User  
**I want to** authenticate using Google  
**So that** I can log in quickly without remembering passwords

**Acceptance Criteria:**
- [ ] “Continue with Google” button visible
- [ ] OAuth flow handled securely
- [ ] Existing accounts linked by email
- [ ] New accounts created if email does not exist
- [ ] User redirected to role-specific dashboard

**Expected Result:** User successfully logged in via Google.

---

### US-1.4: Password Reset
**As a** Registered User  
**I want to** reset my password  
**So that** I can regain access to my account

**Acceptance Criteria:**
- [ ] “Forgot password” link available
- [ ] Reset email sent
- [ ] Reset link valid for limited time (60 minutes)
- [ ] User can define new password

**Expected Result:** User regains account access securely.

---

## 2. Coach Public Profiles

### US-2.1: View Coach Public Profile
**As a** Visitor  
**I want to** view a coach’s public page  
**So that** I can decide whether to subscribe

**Acceptance Criteria:**
- [ ] Public page displays:
    - Profile photo
    - Bio
    - Specialties
    - Certifications
    - Monthly price
    - Before/after gallery
- [ ] “Start Training” CTA visible
- [ ] Only active coaches visible publicly

**Expected Result:** Visitor can evaluate coach before subscribing.

---

### US-2.2: Edit Coach Profile
**As a** Coach  
**I want to** edit my public profile  
**So that** clients see accurate professional information

**Acceptance Criteria:**
- [ ] Coach can update:
    - Bio
    - Certifications
    - Specialties
    - Subscription price
    - Gallery images
- [ ] Changes saved instantly
- [ ] Profile can be toggled public/private

**Expected Result:** Coach profile reflects latest updates.

---

## 3. Subscription & Payments

### US-3.1: Subscribe to Coach
**As a** Client  
**I want to** subscribe to a coach’s monthly plan  
**So that** I can access workout programs

**Acceptance Criteria:**
- [ ] Subscription summary page displayed
- [ ] Shows monthly price
- [ ] Free trial option (7 days) if enabled
- [ ] Stripe checkout integrated
- [ ] On success:
    - Subscription status = active
    - Confirmation email sent
- [ ] On failure:
    - Error displayed
    - Retry option available

**Expected Result:** Client subscription activated successfully.

---

### US-3.2: Commission Split
**As the** Platform  
**I want to** automatically split subscription revenue  
**So that** the platform retains 15% commission

**Acceptance Criteria:**
- [ ] Stripe connected accounts used
- [ ] 15% commission retained
- [ ] 85% transferred to coach
- [ ] Transaction breakdown stored in database

**Expected Result:** Revenue distributed automatically and recorded.

---

### US-3.3: Cancel Subscription
**As a** Client  
**I want to** cancel my subscription  
**So that** I can stop recurring payments

**Acceptance Criteria:**
- [ ] Cancel button visible in account settings
- [ ] Confirmation modal displayed
- [ ] Subscription remains active until period end
- [ ] No further charges applied
- [ ] Coach notified of cancellation

**Expected Result:** Subscription stops renewing after current billing period.

---

## 4. Workout Program Management

### US-4.1: Create Workout Program
**As a** Coach  
**I want to** create structured workout programs  
**So that** I can assign training plans to clients

**Acceptance Criteria:**
- [ ] Coach can create program name and description
- [ ] Add exercises with:
    - Sets
    - Reps
    - Rest time
    - Video link
- [ ] Save as draft or publish
- [ ] Basic version history maintained

**Expected Result:** Workout program created and ready for assignment.

---

### US-4.2: Assign Program to Client
**As a** Coach  
**I want to** assign a workout program to a client  
**So that** they can follow their personalized training plan

**Acceptance Criteria:**
- [ ] Coach selects client
- [ ] Assigns program
- [ ] Client notified via email
- [ ] Client sees program in dashboard immediately

**Expected Result:** Client gains access to assigned program.

---

### US-4.3: Log Workout Completion
**As a** Client  
**I want to** mark exercises as completed  
**So that** my coach can track my progress

**Acceptance Criteria:**
- [ ] Client can mark sets as completed
- [ ] Can log weight used
- [ ] Completion timestamp recorded
- [ ] Coach can see completion logs

**Expected Result:** Workout progress stored and visible to coach.

---

## 5. Progress Tracking

### US-5.1: Track Body Metrics
**As a** Client  
**I want to** log my weight and measurements  
**So that** I can monitor physical progress

**Acceptance Criteria:**
- [ ] Client can add weight entries
- [ ] Client can log measurements (waist, chest, etc.)
- [ ] Historical log stored
- [ ] Line chart shows progress over time

**Expected Result:** Client sees visual representation of progress.

---

### US-5.2: Upload Progress Photos
**As a** Client  
**I want to** upload progress photos  
**So that** I can visually track transformation

**Acceptance Criteria:**
- [ ] Upload image (max size 5MB)
- [ ] Image stored securely
- [ ] Visible only to client and coach
- [ ] Organized by date

**Expected Result:** Photos stored and accessible privately.

---

## 6. Messaging System

### US-6.1: Send Message
**As a** Client or Coach  
**I want to** send messages  
**So that** I can communicate about training

**Acceptance Criteria:**
- [ ] Real-time or near real-time updates
- [ ] Message stored in database
- [ ] Read/unread status visible
- [ ] Notifications sent on new message

**Expected Result:** Users can communicate efficiently within the platform.

---

## 7. Admin Dashboard

### US-7.1: View All Users
**As an** Admin  
**I want to** view all registered users  
**So that** I can manage the platform

**Acceptance Criteria:**
- [ ] Filter by role (Client/Coach)
- [ ] Search by name/email
- [ ] Suspend account option available

---

### US-7.2: View Revenue Dashboard
**As an** Admin  
**I want to** see platform revenue metrics  
**So that** I can track business performance

**Acceptance Criteria:**
- [ ] Total revenue
- [ ] Monthly recurring revenue (MRR)
- [ ] Active subscriptions count
- [ ] Churn rate
- [ ] Revenue over time chart

---

## Appendix: User Story Status

| ID | Story | Priority | Status |
|----|-------|----------|--------|
| US-1.1 | Client Registration | High | Pending |
| US-1.2 | Coach Registration | High | Pending |
| US-3.1 | Subscribe to Coach | High | Pending |
| US-4.1 | Create Workout Program | High | Pending |
| US-6.1 | Messaging | Medium | Pending |
| US-7.2 | Revenue Dashboard | Medium | Pending |