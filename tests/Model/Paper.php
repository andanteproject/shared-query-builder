<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\Tests\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Paper
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(Types::STRING, nullable: true)]
    private ?string $name = null;

    /** @var Collection<int, Employee> */
    #[ORM\OneToMany(Employee::class, mappedBy: 'papers')]
    private Collection $employees;

    #[ORM\ManyToOne(Document::class)]
    private ?Document $document = null;

    public function __construct()
    {
        $this->employees = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): self
    {
        $this->document = $document;
        return $this;
    }

    /**
     * @return Collection<int, Employee>
     */
    public function getEmployees(): Collection
    {
        return $this->employees;
    }

    public function addEmployee(Employee $employee): self
    {
        $this->employees->add($employee);
        return $this;
    }

    public function removeEmployee(Employee $employee): self
    {
        $this->employees->removeElement($employee);
        return $this;
    }
}
