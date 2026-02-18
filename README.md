![Andante Project Logo](https://github.com/andanteproject/shared-query-builder/blob/main/andanteproject-logo.png?raw=true)

# Shared Query Builder

#### Doctrine 2/3 [Query Builder](https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/query-builder.html) decorator - [AndanteProject](https://github.com/andanteproject)

[![Latest Version](https://img.shields.io/github/release/andanteproject/shared-query-builder.svg)](https://github.com/andanteproject/shared-query-builder/releases)
![Github actions](https://github.com/andanteproject/shared-query-builder/actions/workflows/ci.yml/badge.svg?branch=main)
![Php8](https://img.shields.io/badge/PHP-8.x-informational?style=flat&logo=php)
![PhpStan](https://img.shields.io/badge/PHPStan-Level%208-success?style=flat&logo=php)

A Doctrine 2 [Query Builder](https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/query-builder.html)
decorator that makes it easier to build your query in shared contexts.

## Table of contents

- [Why do I need this?](#why-do-i-need-this)
- [Features](#features)
- [Requirements](#requirements)
- [Install](#install)
- [Set up](#set-up)
- [Which additional methods do I have?](#which-additional-methods-do-i-have)
  - [Entity methods](#entity-methods)
  - [Lazy joins](#lazy-joins)
  - [Proposals](#proposals)
    - [Creating a proposal](#creating-a-proposal)
    - [Collect API (no side effects until use)](#collect-api-no-side-effects-until-use)
    - [Merging by use](#merging-by-use)
    - [Consumed state and reuse](#consumed-state-and-reuse)
    - [Introspection and clear](#introspection-and-clear)
    - [Example: filter as a proposal](#example-filter-as-a-proposal)
- [Examples](#examples)
  - [A real world case](#a-real-world-case)
  - [That's why SharedQueryBuilder is going to save your ass in these situations](#thats-why-sharedquerybuilder-is-going-to-save-your-ass-in-these-situations)
  - [Immutable Parameters](#immutable-parameters)
  - [Set parameter and use it in expression at the same moment](#set-parameter-and-use-it-in-expression-at-the-same-moment)
  - [Unique parameters](#unique-parameters)
  - [Conclusion](#conclusion)

## Why do I need this?

When your query business logic is big and complex you are probably going to split its building process to different
places/classes.

Without `SharedQueryBuilder` there is no way to do that unless *guessing Entity aliases* and messing with *join
statements*.

This [query builder](https://www.doctrine-project.org/projects/doctrine-orm/en/3.2/reference/query-builder.html)
decorator addresses some problems you can find in a real world situation you usually solve with workarounds and business
conventions.

## Features

- Ask [query builder](https://www.doctrine-project.org/projects/doctrine-orm/en/3.2/reference/query-builder.html)
  which alias is used for an entity when you are outside its creation context;
- **Lazy joins** to declare join statements to be performed only if related criteria are defined;
- **Immutable** and **unique** query **parameters**;
- **Proposals**: collect conditions, joins, parameters (and select/groupBy/orderBy/having) in a temporary object and merge them into the main query by using the proposal in `andWhere` / `orWhere`—ideal for strategies or filters that need to contribute a whole “block” of DQL;
- Works like magic ✨.

## Requirements

Doctrine 3 and PHP 8.0 or higher.

## Install

Via [Composer](https://getcomposer.org/):

```bash
$ composer require andanteproject/shared-query-builder
```

## Set up

After creating
your [query builder](https://www.doctrine-project.org/projects/doctrine-orm/en/3.2/reference/query-builder.html), wrap
it inside our `SharedQueryBuilder`.

```php
use Andante\Doctrine\ORM\SharedQueryBuilder;

// $qb instanceof Doctrine\ORM\QueryBuilder
// $userRepository instanceof Doctrine\ORM\EntityRepository
$qb = $userRepository->createQueryBuilder('u');
// Let's wrap query builder inside our decorator.
// We use $sqb as acronym of "Shared Query Builder"
$sqb = SharedQueryBuilder::wrap($qb);
```

From now on, you can use `$sqb` exactly as you usually do
with [query builder](https://www.doctrine-project.org/projects/doctrine-orm/en/3.2/reference/query-builder.html) (every
single method of `QueryBuilder` is available on `SharedQueryBuilder`), **but with some useful extra methods** 🤫.

When you're done building your query, just **unwrap** your `SharedQueryBuilder`.

```php
// $sqb instanceof Andante\Doctrine\ORM\SharedQueryBuilder
// $qb instanceof Doctrine\ORM\QueryBuilder
$qb = $sqb->unwrap();
```

#### Please note:

- The only condition applied to build a `SharedQueryBuilder` is that no join statements may be declared yet.
- `SharedQueryBuilder` is *a decorator* of `QueryBuilder`, which means it is not an `instance of QueryBuilder` even if
  it has all its methods (sadly, Doctrine has no QueryBuilder Interface 🥺).
- `SharedQueryBuilder` does not allow you to join an Entity multiple times with different aliases.

## Which additional methods do I have?

### Entity methods

You can ask the `SharedQueryBuilder` if it has and entity in the `from` statement or some `join` statements.

```php
if($sqb->hasEntity(User::class)) // bool returned 
{ 
    // Apply some query criteria only if this query builder is handling the User entity
}
```

You can ask which is the alias of an Entity inside the query you're building (no matter if it is used in a `from`
statement or a `join` statement).

```php
$userAlias = $sqb->getAliasForEntity(User::class); // string 'u' returned 
```

You can use `withAlias` method to smoothly add a condition for that entity property:

```php
if($sqb->hasEntity(User::class)) // bool returned 
{ 
    $sqb
        ->andWhere(
            $sqb->expr()->eq(
                $sqb->withAlias(User::class, 'email'), // string 'u.email'
                ':email_value'
            )
        )
        ->setParameter('email_value', 'user@email.com')
    ;    
} 
```

Given an alias, you can retrieve its entity class:

```php
$entityClass = $sqb->getEntityForAlias('u'); // string 'App\Entity\User' returned
```

`QueryBuilder::getAllAliases` is extended to have an optional `bool` argument `$includeLazy` (default:`false`) to
include [lazy joins](#lazy-joins) aliases.

```php
$allAliases = $sqb->getAllAliases(true);
```

### Lazy joins

All query builder `join` methods can be used as usual, but you can also use them with "`lazy`" prefix.

```php
// Common join methods
$sqb->join(/* args */);
$sqb->innerJoin(/* args */);
$sqb->leftJoin(/* args */);

// Lazy join methods
$sqb->lazyJoin(/* args */);
$sqb->lazyInnerJoin(/* args */);
$sqb->lazyLeftJoin(/* args */);

// They work with all the ways you know you can perform joins in Doctrine
// A: $sqb->lazyJoin('u.address', 'a') 
// or B: $sqb->lazyJoin('Address::class', 'a', Expr\Join::WITH, $sqb->expr()->eq('u.address','a')) 
```

By doing this, you are defining a `join` statement **without actually adding it** to your DQL query. It is going to be
added to your DQL query only when you add **another condition/dql part** which refers to it. Automagically ✨.

Based on how confused you are right now, you can check [why you should need this](#why-do-i-need-this)
or [some examples](#examples) to achieve your "OMG" revelation moment.

### Proposals

When you split query building across multiple strategies or filter classes, you often want each one to contribute a **block** of logic: several conditions, joins, parameters, and maybe select/groupBy/orderBy/having. **Proposals** let you collect that block in a temporary object and “merge” it into the main `SharedQueryBuilder` in one go—by using the proposal inside `andWhere`, `orWhere`, `where`, `andHaving`, or `orHaving`. There is no separate `merge()` call: **merging happens when the proposal is used in one of those methods.**

#### Creating a proposal

Create an empty proposal from the `SharedQueryBuilder`; you can give it a name (useful for debugging) or leave it empty to get a unique auto-generated name.

```php
// Named proposal
$proposal = $sqb->createEmptyProposal('building_filter');

// Anonymous proposal (unique name generated automatically)
$proposal = $sqb->createEmptyProposal();
```

#### Collect API (no side effects until use)

A proposal exposes the same method names as the SQB for building a **local** set of conditions, joins, parameters, and other parts. Nothing is written to the main query until the proposal is used in an expression.

```php
$proposal = $sqb->createEmptyProposal('status_filter');

// Conditions
$proposal->andWhere('u.status = ' . $proposal->withUniqueImmutableParameter('status', 'active'));
$proposal->orWhere('u.role = ' . $proposal->withUniqueImmutableParameter('role', 'admin'));

// Joins (added to the main SQB only when the proposal is expanded)
$proposal->innerJoin('u.profile', 'p');

// Optional: select, groupBy, orderBy, having
$proposal->addSelect('u.id')->addGroupBy('u.id')->addOrderBy('u.createdAt', 'DESC');
```

- **Parameters**: use only `withUniqueImmutableParameter` on the proposal; on expansion, parameter names are made unique on the main SQB and the condition DQL is updated accordingly.
- **Nested proposals**: you can add another proposal as a condition: `$proposal->andWhere($nestedProposal)`. When the parent is expanded, nested proposals are expanded recursively.

#### Merging by use

To merge a proposal into the main query, pass it to `andWhere`, `orWhere`, `where`, `andHaving`, or `orHaving`. The SQB will expand the proposal (apply its joins, parameters, select/groupBy/orderBy/having, build the condition, and replace the proposal with the resulting DQL).

```php
$sqb->select('u')->from(User::class, 'u');

$statusProposal = $sqb->createEmptyProposal('status');
$statusProposal->andWhere('u.status = ' . $statusProposal->withUniqueImmutableParameter('s', 'active'));

$sqb->andWhere($statusProposal);
// Now the main query has the proposal’s condition and parameter; its joins/select/etc. would be applied too if we had added any.
```

You can combine multiple proposals in an OR (or AND) by expanding them first and passing the resulting strings to `expr()->orX()` (or `expr()->andX()`):

```php
$proposal1 = $sqb->createEmptyProposal('p1');
$proposal1->andWhere('u.role = ' . $proposal1->withUniqueImmutableParameter('r', 'admin'));

$proposal2 = $sqb->createEmptyProposal('p2');
$proposal2->andWhere('u.role = ' . $proposal2->withUniqueImmutableParameter('r', 'editor'));

$sqb->andWhere($sqb->expr()->orX(
    $proposal1->expandInto($sqb),
    $proposal2->expandInto($sqb)
));
// Main query has (condition1 OR condition2) and both parameters.
```

#### Consumed state and reuse

After a proposal is expanded for the first time, it is marked **consumed**. Using the same proposal again in another `andWhere`/`orWhere` is a no-op: it expands to a neutral `1=1` so the query result is unchanged. Cloning a proposal gives a non-consumed copy with the same collected state.

#### Introspection and clear

- **Introspection**: `hasConditions()`, `hasJoins()`, `hasParameters()`, `isEmpty()`, `isConsumed()`.
- **Clear**: `clearWhere()`, `clearJoins()`, `clearParameters()`, `clearSelect()`, `clearGroupBy()`, `clearOrderBy()`, `clearHaving()`, `clearAll()`.

#### Example: filter as a proposal

A filter class can build a proposal and the controller merges it in one place:

```php
// StatusFilter.php
class StatusFilter implements FilterInterface
{
    public function apply(SharedQueryBuilder $sqb, Request $request): void
    {
        $status = $request->query->get('status');
        if ($status === null) {
            return;
        }
        $proposal = $sqb->createEmptyProposal('status_filter');
        $proposal->andWhere(
            'u.status = ' . $proposal->withUniqueImmutableParameter('status', $status)
        );
        $sqb->andWhere($proposal);
    }
}
```

This keeps the filter responsible only for its own conditions and parameters, and avoids alias or parameter name clashes with other filters.

## Examples

Let's suppose we need to list `User` entities but we also have an **optional filter** to search a user by their
address `Building` name.

There is no need to perform any join until we decide to use that filter. We can use **Lazy Join** to achieve this.

```php
$sqb = SharedQueryBuilder::wrap($userRepository->createQueryBuilder('u'));
$sqb
    ->lazyJoin('u.address', 'a')
    ->lazyJoin('a.building', 'b')
    //Let's add a WHERE condition that do not need our lazy joins 
    ->andWhere(
        $sqb->expr()->eq('u.verifiedEmail', ':verified_email')
    )
    ->setParameter('verified_email', true)
;

$users = $sqb->getQuery()->getResult();
// DQL executed:
//     SELECT u
//     FROM App\Entity\User
//     WHERE u.verifiedEmail = true

// BUT if we use the same Query Builder to filter by building.name:
$buildingNameFilter = 'Building A';
$sqb
    ->andWhere(
        $sqb->expr()->eq('b.name', ':name_value')
    )
    ->setParameter('name_value', $buildingNameFilter)
;
$users = $sqb->getQuery()->getResult();
// DQL executed:
//     SELECT u
//     FROM App\Entity\User
//       JOIN u.address a
//       JOIN a.building b
//     WHERE u.verifiedEmail = true
//       AND b.name = 'Building A'
```

You are probably thinking: **why don't we achieve the same result with the following, more common, way**? (keep in mind
that avoiding unnecessary joins is still a requirement)

```php
// How you could achieve this without SharedQueryBuilder
$buildingNameFilter = 'Building A';
$qb = $userRepository->createQueryBuilder('u');
$qb
    ->andWhere(
        $qb->expr()->eq('u.verifiedEmail', ':verified_email')
    )
    ->setParameter('verified_email', true);
    
if(!empty($buildingNameFilter)){
    $qb
        ->lazyJoin('u.address', 'a')
        ->lazyJoin('a.building', 'b')
        ->andWhere(
            $qb->expr()->eq('b.name', ':building_name_value')
        )
        ->setParameter('building_name_value', $buildingNameFilter)
    ;
}

$users = $qb->getQuery()->getResult(); // Same result as example shown before
// But this has some down sides further explained
```

The code above is perfectly fine if you build this whole query in the **same context**:

- 👍 You are *aware* of the whole query building process;
- 👍 You are *aware* of which entities are involved;
- 👍 You are *aware* of which alias are defined for each entity.
- 👍 You are *aware* of which query parameters are defined and their purpose.

But you have problems:

- 👎 You are mixing query structure definition with optional filtering criteria.
- 👎 Code is quickly going to be an unreadable mess.

### A real world case

If your query structure grows with lots of joins and filtering criteria, you are probably going to split all that
business logic in different classes.

For instance, in a backoffice Users list, you are probably going to define your *main query* to list entities in your
controller and handle **optional filters** in some **other classes**.

```php
// UserController.php
class UserController extends Controller
{
    public function index(Request $request, UserRepository $userRepository) : Response
    {
        $qb = $userRepository->createQueryBuilder('u');
        $qb
            ->andWhere(
                $qb->expr()->eq('u.verifiedEmail', ':verified_email')
            )
            ->setParameter('verified_email', true);
        
        // Now Apply some optional filters from Request
        // Let's suppose we have an "applyFilters" method which is giving QueryBuilder and Request
        // to an array of classes responsible for filtering query results.  
        $this->applyFilters($qb, $request);
        
        // Maybe have some pagination logic here too. Check KnpLabs/knp-components which is perfect for this.
        
        $users = $qb->getQuery()->getResults();
        // Build our response with User entities list.
    }
}
```

Filter classes may look like this:

```php
// BuildingNameFilter.php
class BuildingNameFilter implements FilterInterface
{
    public function filter(QueryBuilder $qb, Request $request): void
    {
        $buildingNameFilter = $request->query->get('building-name');
        if(!empty($buildingNameFilter)){
            $qb
                ->join('u.address', 'a')
                ->join('a.building', 'b')
                ->andWhere(
                    $qb->expr()->eq('b.name', ':building_name_value')
                )
                ->setParameter('building_name_value', $buildingNameFilter)
            ;
        }
    }
}
```

**We are committing some multiple sins here! 💀 The context is changed.**

- 👎 You are *not aware* of the whole query building process. Is the given QueryBuilder even a query on User entity?;
- 👎 You are *not aware* of which entities are involved. Which entities have already been joined?;
- 👎 You are *not aware* of which aliases are defined for each entity. No way we are calling `u.address` by convention
  🤨;
- 👎 You are *aware* of what parameters have been defined (`$qb->getParameters()`), but you are *not aware* why they
  have been defined, for which purpose and you can also *override* them changing elsewhere behavior;
- 👎 Our job in this context is just to apply some filter. We *can* change the query by adding some join statements but
  we *should avoid* that. What if another filter also need to perform those joins? Devastating. 😵

#### That's why SharedQueryBuilder is going to save your ass in these situations

Let's see how we can solve all these problems with `SharedQueryBuilder` (you can now guess why it is named like this).

Using `SharedQueryBuilder` you can:

- 👍 Define **lazy join** to allow them to be performed only if they are needed;
- 👍 Define some parameters **immutable** to be sure value is not going to be changed elsewhere;
- 👍 You can **check if an entity is involved in a query** and then apply some business logic;
- 👍 You can **ask the query builder** which *alias* is used for a specific entity so you are not going to guess aliases
  or sharing them between classes using constants (I know you thought of that 🧐).

```php
// UserController.php
use Andante\Doctrine\ORM\SharedQueryBuilder;

class UserController extends Controller
{
    public function index(Request $request, UserRepository $userRepository) : Response
    {
        $sqb = SharedQueryBuilder::wrap($userRepository->createQueryBuilder('u'));
        $sqb
            // Please note: Sure, you can mix "normal" join methods and "lazy" join methods
            ->lazyJoin('u.address', 'a')
            ->lazyJoin('a.building', 'b')
            ->andWhere($sqb->expr()->eq('u.verifiedEmail', ':verified_email'))
            ->setImmutableParameter('verified_email', true);
        
        // Now Apply some optional filters from Request
        // Let's suppose we have an "applyFilters" method which is giving QueryBuilder and Request
        // to an array of classes responsible for filtering query results.  
        $this->applyFilters($sqb, $request);
        
        // Maybe have some pagination logic here too.
        // You probably need to unwrap the Query Builder now for this
        $qb = $sqb->unwrap();
        
        $users = $qb->getQuery()->getResult();
        // Build our response with User entities list.
    }
}
```

Filter classes will look like this:

```php
// BuildingNameFilter.php
use Andante\Doctrine\ORM\SharedQueryBuilder;

class BuildingNameFilter implements FilterInterface
{
    public function filter(SharedQueryBuilder $sqb, Request $request): void
    {
        $buildingNameFilter = $request->query->get('building-name');
        // Let's check if Query has a Building entity in from or join DQL parts 🙌
        if($sqb->hasEntity(Building::class) && !empty($buildingNameFilter)){
            $sqb
                ->andWhere(
                    // We can ask Query builder for the "Building" alias instead of guessing it/retrieve somewhere else 💋
                    $sqb->expr()->eq($sqb->withAlias(Building::class, 'name'), ':building_name_value')
                    // You can also use $sqb->getAliasForEntity(Building::class) to discover alias is 'b';
                )
                ->setImmutableParameter('building_name_value', $buildingNameFilter)
            ;
        }
    }
}
```

- 👍 No extra join statements executed when there is no need for them;
- 👍 No way to change/override parameters value once defined;
- 👍 We can discover if the Query Builder is handling an Entity and then apply our business logic;
- 👍 We are not guessing entity aliases;
- 👍 Our filter class is only responsible for filtering;
- 👍 There can be multiple filter class handling different criteria on the same entity without having duplicated join
  statements;

#### Immutable Parameters

Shared query builder has **Immutable Parameters**. Once defined, they cannot be changed; otherwise an *Exception* will
be raised.

```php
// $sqb instanceof Andante\Doctrine\ORM\SharedQueryBuilder

// set a common Query Builder parameter, as you are used to 
$sqb->setParameter('parameter_name', 'parameterValue');

// set an immutable Query Builder parameter. It cannot be changed; otherwise an exception will be raised.
$sqb->setImmutableParameter('immutable_parameter_name', 'parameterValue');

// get a collection of all query parameters (commons + immutables!)
$sqb->getParameters();

// get a collection of all immutable query parameters (exclude commons)
$sqb->getImmutableParameters();

// Sets a parameter and return parameter name as string instead of $sqb.
$sqb->withParameter(':parameter_name', 'parameterValue');
$sqb->withImmutableParameter(':immutable_parameter_name', 'parameterValue');
// This allows you to write something like this:
$sqb->expr()->eq('building.name', $sqb->withParameter(':building_name_value', $buildingNameFilter));

// The two following methods sets "unique" parameters. See "Unique parameters" doc section for more...
$sqb->withUniqueParameter(':parameter_name', 'parameterValue');
$sqb->withUniqueImmutableParameter(':parameter_name', 'parameterValue');
```

#### Set parameter and use it in expression at the same moment

If you are sure you are not going to use a parameter in multiple places inside your query, you can write the following
code 🙌

```php
$sqb
    ->andWhere(
        $sqb->expr()->eq(
            $sqb->withAlias(Building::class, 'name'), 
            ':building_name_value'
        )
    )
    ->setImmutableParameter('building_name_value', $buildingNameFilter)
;
```

this way 👇👇👇

```php
$sqb
    ->andWhere(
        $sqb->expr()->eq(
            $sqb->withAlias(Building::class, 'name'), 
            $sqb->withImmutableParameter(':building_name_value', $buildingNameFilter) // return ":building_name_value" but also sets immutable parameter
        )
    )
;

```

#### Unique parameters

Beside [immutable parameters](#immutable-parameters), you can also demand query builder the generation of a parameter
name. Using the following methods, query builder will decorate names to avoid conflicts with already declared ones (
which cannot even happen with immutable parameters).

```php
$sqb
    ->andWhere(
        $sqb->expr()->eq(
           'building.name', 
            $sqb->withUniqueParameter(':name', $buildingNameFilter) // return ":param_name_4b3403665fea6" making sure parameter name is not already in use and sets parameter value.
        )
    )
    ->andWhere(
        $sqb->expr()->gte(
           'building.createdAt', 
            $sqb->withUniqueImmutableParameter(':created_at', new \DateTime('-5 days ago'))  // return ":param_created_at_5819f3ad1c0ce" making sure parameter name is not already in use and sets immutable parameter value.
        )
    )
    ->andWhere(
        $sqb->expr()->lte(
           'building.createdAt',
            $sqb->withUniqueImmutableParameter(':created_at', new \DateTime('today midnight'))  // return ":param_created_at_604a8362bf00c" making sure parameter name is not already in use and sets immutable parameter value.
        )
    )
;

/* 
 * Query Builder has now 3 parameters:
 *  - param_name_4b3403665fea6 (common)
 *  - param_created_at_5819f3ad1c0ce (immutable)
 *  - param_created_at_604a8362bf00c (immutable)
 */
```

### Conclusion

The world is a happier place 💁.

Give us a ⭐️ if your world is now a happier place too! 💃🏻

Built with love ❤️ by [AndanteProject](https://github.com/andanteproject) team.
